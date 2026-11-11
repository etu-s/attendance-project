<?php

declare(strict_types=1);

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Leave\Models\LeaveRequestAttachment;
use App\Modules\Leave\Tests\Support\LeaveTesting;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class, LeaveTesting::class);

/*
 * E-1 (the worker's own record, in the manager's department) asks for
 * Monday 9 March 2026 and attaches a certificate.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC'));
    $this->leaveWorkspace();
    $this->requestId = $this->actingAsTenantUser($this->worker)
        ->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-09', '2026-03-09'))
        ->assertCreated()->json('data.id');
    $this->files = $this->tenantUrl($this->tenant, "leave-requests/{$this->requestId}/attachments");
});

afterEach(function (): void {
    $this->tenant->run(fn () => File::deleteDirectory(storage_path()));
});

function certificate(string $name = 'certificate.pdf', int $kilobytes = 120, string $mime = 'application/pdf'): UploadedFile
{
    return UploadedFile::fake()->create($name, $kilobytes, $mime);
}

it('lets the employee attach a file that approvers and HR can download', function (): void {
    // The browser's path part is dropped; only the base name is kept.
    $id = $this->postJson($this->files, ['file' => certificate('C:/Users/ada/Dr. Khan / sick note.pdf')])
        ->assertCreated()
        ->assertJsonPath('data.name', 'sick note.pdf')
        ->assertJsonPath('data.mime_type', 'application/pdf')
        ->assertJsonPath('data.size_bytes', 120 * 1024)
        ->assertJsonPath('data.can_remove', true)
        ->json('data.id');

    // The stored file has a random name inside the tenant's own storage.
    $path = $this->tenant->run(fn () => LeaveRequestAttachment::query()->findOrFail($id)->path);
    expect($path)->toStartWith("leave-attachments/{$this->requestId}/")->not->toContain('sick')
        ->and($this->tenant->run(fn () => Storage::disk('local')->exists($path)))->toBeTrue();

    $this->getJson($this->tenantUrl($this->tenant, "leave-requests/{$this->requestId}"))
        ->assertJsonCount(1, 'data.attachments')->assertJsonPath('data.can_attach', true);

    foreach ([$this->worker, $this->manager, $this->hr] as $user) {
        $this->actingAsTenantUser($user)->get("{$this->files}/{$id}")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('sick note.pdf');
    }

    // Another employee cannot see it, nor learn it exists.
    $colleague = $this->createTenantUser($this->tenant, RoleName::Employee);
    $this->tenant->run(fn () => Employee::factory()->inDepartment($this->department)->create(['user_id' => $colleague->id, 'hire_date' => '2026-01-01']));
    $this->actingAsTenantUser($colleague)->get("{$this->files}/{$id}")->assertNotFound();
    $this->postJson($this->files, ['file' => certificate()])->assertNotFound();

    $this->tenant->run(fn () => expect(AuditLog::query()->where('auditable_type', 'leave_request_attachment')->count())->toBe(1));
});

it('accepts PDF, JPG and PNG up to 5 MB, three per request', function (): void {
    $this->postJson($this->files, ['file' => certificate('scan.exe', 10, 'application/x-msdownload')])->assertJsonValidationErrors('file');
    $this->postJson($this->files, ['file' => certificate('notes.txt', 10, 'text/plain')])->assertJsonValidationErrors('file');
    $this->postJson($this->files, ['file' => certificate('huge.pdf', 5121)])->assertJsonValidationErrors(['file' => 'at most 5 MB']);
    $this->postJson($this->files, [])->assertJsonValidationErrors('file');

    $this->postJson($this->files, ['file' => certificate('a.pdf')])->assertCreated();
    $this->postJson($this->files, ['file' => certificate('b.jpg', 80, 'image/jpeg')])->assertCreated()->assertJsonPath('data.name', 'b.jpg');
    $this->postJson($this->files, ['file' => certificate('c.png', 80, 'image/png')])->assertCreated();
    $this->postJson($this->files, ['file' => certificate('d.pdf')])->assertStatus(409)->assertJsonPath('rule', 'leave_attachment_limit');
});

it('lets the uploader or HR remove files while pending, and keeps them once approved', function (): void {
    $first = $this->postJson($this->files, ['file' => certificate()])->json('data.id');
    $second = $this->postJson($this->files, ['file' => certificate('second.pdf')])->json('data.id');

    // The manager may download but not remove someone else's file.
    $this->actingAsTenantUser($this->manager)->deleteJson("{$this->files}/{$first}")->assertForbidden();
    $this->actingAsTenantUser($this->worker)->deleteJson("{$this->files}/{$first}")->assertNoContent();
    $this->actingAsTenantUser($this->manager)->postJson($this->tenantUrl($this->tenant, "leave-requests/{$this->requestId}/approve"))->assertOk();

    $this->actingAsTenantUser($this->worker)->deleteJson("{$this->files}/{$second}")->assertForbidden();
    // A certificate may still arrive after approval.
    $this->postJson($this->files, ['file' => certificate('late.pdf')])->assertCreated()->assertJsonPath('data.can_remove', false);
    $this->actingAsTenantUser($this->hr)->postJson($this->files, ['file' => certificate('hr-copy.pdf')])->assertCreated();

    $this->tenant->run(fn () => expect(LeaveRequestAttachment::query()->count())->toBe(3));
});

it('deletes the files of a pending request that is cancelled, and refuses new ones', function (): void {
    $id = $this->postJson($this->files, ['file' => certificate()])->json('data.id');
    $path = $this->tenant->run(fn () => LeaveRequestAttachment::query()->findOrFail($id)->path);

    $this->postJson($this->tenantUrl($this->tenant, "leave-requests/{$this->requestId}/cancel"))->assertOk();

    $this->tenant->run(fn () => expect(LeaveRequestAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->exists($path))->toBeFalse());
    $this->postJson($this->files, ['file' => certificate()])->assertStatus(409)->assertJsonPath('rule', 'leave_attachment_forbidden');
});

it('keeps tenant B\'s files out of tenant A', function (): void {
    $acme = $this->tenant;
    $id = $this->postJson($this->files, ['file' => certificate()])->json('data.id');
    $acmeFile = $this->files;

    $this->leaveWorkspace('globex');
    // Same ids on another host point at globex's own (empty) data.
    $this->actingAsTenantUser($this->hr)->get(str_replace('acme.', 'globex.', $acmeFile).'/'.$id)->assertNotFound();
    $this->tenant->run(fn () => expect(LeaveRequestAttachment::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]));
    $acme->run(fn () => expect(Storage::disk('local')->allFiles())->toHaveCount(1));

    $this->tenant = $acme;
});
