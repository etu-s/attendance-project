/** Mirrors App\Modules\Core\Enums\RoleName */
export const roles = ['tenant-admin', 'hr-manager', 'department-manager', 'employee'] as const
export type Role = (typeof roles)[number]

export const roleLabels: Record<Role, string> = {
  'tenant-admin': 'Tenant Admin',
  'hr-manager': 'HR Manager',
  'department-manager': 'Department Manager',
  employee: 'Employee',
}

/** Mirrors App\Modules\Identity\Enums\UserStatus */
export const userStatuses = ['active', 'inactive'] as const
export type UserStatus = (typeof userStatuses)[number]

/** Mirrors UserResource.php */
export interface User {
  id: number
  name: string
  email: string
  status: UserStatus
  role: Role | null
  last_login_at: string | null
  created_at: string
  updated_at: string
}
