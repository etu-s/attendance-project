<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Organization\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;

class ImportEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('import', Employee::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // 2 MB ≈ well over 5,000 rows; the row cap is enforced while parsing.
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ];
    }
}
