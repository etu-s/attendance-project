import { z } from 'zod'
import { employmentTypes } from './api'

/*
 * Mirrors StoreEmployeeRequest / UpdateEmployeeRequest. Uniqueness of codes and
 * emails, the plan limit and "email already has a login" are checked by the
 * API and mapped back onto the fields.
 */
const date = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Pick a date.')

const password = z
  .string()
  .min(8, 'Use at least 8 characters.')
  .regex(/\p{L}/u, 'Include at least one letter.')
  .regex(/\d/, 'Include at least one number.')

const profile = {
  employee_code: z
    .string()
    .trim()
    .min(1, 'Enter an employee code.')
    .max(30)
    .regex(/^[A-Za-z0-9._-]+$/, 'Use letters, numbers, dots, dashes or underscores only.'),
  first_name: z.string().trim().min(1, 'Enter a first name.').max(80),
  last_name: z.string().trim().min(1, 'Enter a last name.').max(80),
  email: z.union([z.literal(''), z.email('Enter a valid email address.').max(255)]),
  phone: z.string().max(30),
  job_title: z.string().max(120),
  employment_type: z.enum(employmentTypes),
  hire_date: date,
}

export const createEmployeeSchema = z
  .object({
    ...profile,
    department_id: z.string().min(1, 'Choose a department.'),
    location_id: z.string(),
    group_ids: z.array(z.number()),
    create_login: z.boolean(),
    login_password: z.string(),
  })
  .superRefine((v, ctx) => {
    if (!v.create_login) return
    if (v.email === '') ctx.addIssue({ code: 'custom', path: ['email'], message: 'An email is required to create a login.' })
    const check = password.safeParse(v.login_password)
    if (!check.success)
      ctx.addIssue({ code: 'custom', path: ['login_password'], message: check.error.issues[0]?.message ?? 'Invalid password.' })
  })

export const updateEmployeeSchema = z.object({
  ...profile,
  status: z.enum(['active', 'inactive']),
})

export type CreateEmployeeValues = z.infer<typeof createEmployeeSchema>
export type UpdateEmployeeValues = z.infer<typeof updateEmployeeSchema>
