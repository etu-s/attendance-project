<?php

declare(strict_types=1);

namespace App\Modules\Organization;

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Events\TenantDefaultsSyncRequested;
use App\Modules\Core\Permissions\PermissionCatalog;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Identity\Events\UserDeleted;
use App\Modules\Organization\Listeners\ReleaseDeletedUser;
use App\Modules\Organization\Listeners\SeedOrganizationDefaults;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\EmployeeAssignment;
use App\Modules\Organization\Models\EmployeeImport;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Models\GroupMembership;
use App\Modules\Organization\Models\Location;
use App\Modules\Organization\Models\OrganizationProfile;
use App\Modules\Organization\Policies\EmployeePolicy;
use App\Modules\Organization\Services\AssignmentService;
use App\Modules\Organization\Services\DepartmentService;
use App\Modules\Organization\Services\DepartmentTree;
use App\Modules\Organization\Services\EmployeeImportService;
use App\Modules\Organization\Services\EmployeeService;
use App\Modules\Organization\Services\EmployeeVisibility;
use App\Modules\Organization\Services\GroupService;
use App\Modules\Organization\Services\LocationService;
use App\Modules\Organization\Services\ManagerAssignments;
use App\Modules\Organization\Services\MembershipService;
use App\Modules\Organization\Services\OrganizationProfileService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

/**
 * Organization & Employees: the structure (profile, locations, departments,
 * groups) and employees with effective-dated assignment history. Later
 * modules resolve "department/location/groups on date D" through
 * AssignmentService::on() / MembershipService::on(), and scope employee data
 * through EmployeeVisibility.
 */
final class OrganizationServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        foreach ([
            AssignmentService::class, MembershipService::class, DepartmentTree::class, EmployeeVisibility::class,
            ManagerAssignments::class, OrganizationProfileService::class, LocationService::class,
            DepartmentService::class, GroupService::class, EmployeeService::class, EmployeeImportService::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $this->bootModule();

        Relation::enforceMorphMap([
            'organization_profile' => OrganizationProfile::class,
            'location' => Location::class,
            'department' => Department::class,
            'group' => Group::class,
            'employee' => Employee::class,
            'employee_assignment' => EmployeeAssignment::class,
            'group_membership' => GroupMembership::class,
            'employee_import' => EmployeeImport::class,
        ]);

        Gate::policy(Employee::class, EmployeePolicy::class);

        $this->registerPermissions();

        Event::listen(TenantDefaultsSyncRequested::class, SeedOrganizationDefaults::class);
        Event::listen(UserDeleted::class, ReleaseDeletedUser::class);
    }

    private function registerPermissions(): void
    {
        $catalog = $this->app->make(PermissionCatalog::class);
        $hr = RoleName::HrManager;
        $manager = RoleName::DepartmentManager;

        $catalog->register('organization.view', [$hr, $manager]);
        $catalog->register('organization.update');
        $catalog->register('locations.view', [$hr, $manager]);
        $catalog->register('locations.manage', [$hr]);
        $catalog->register('departments.view', [$hr, $manager]);
        $catalog->register('departments.manage', [$hr]);
        $catalog->register('groups.view', [$hr, $manager]);
        $catalog->register('groups.manage', [$hr]);
        // employees.view is scoped by EmployeeVisibility; view-all is everyone.
        $catalog->register('employees.view', [$hr, $manager]);
        $catalog->register('employees.view-all', [$hr]);
        $catalog->register('employees.create', [$hr]);
        $catalog->register('employees.update', [$hr]);
        $catalog->register('employees.terminate', [$hr]);
        $catalog->register('employees.import', [$hr]);
        $catalog->register('employees.delete');
    }
}
