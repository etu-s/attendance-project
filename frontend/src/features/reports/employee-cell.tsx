import { Link } from 'react-router'
import type { EmployeeRef } from './api'

export function EmployeeCell({ employee }: { employee: EmployeeRef | null }) {
  if (!employee) return <span className="text-muted-foreground">Deleted</span>

  return (
    <div className="min-w-0">
      <Link to={`/employees/${String(employee.id)}`} className="font-medium underline-offset-4 hover:underline">
        {employee.full_name}
      </Link>
      <p className="text-muted-foreground text-xs">{employee.employee_code}</p>
    </div>
  )
}
