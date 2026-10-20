import { z } from 'zod'
import { roles, userStatuses } from './types'

/*
 * Mirrors StoreUserRequest / UpdateUserRequest. Password rule = Laravel's
 * Password::min(8)->letters()->numbers(). Uniqueness and "who may assign
 * Tenant Admin" are checked server-side and mapped back onto the fields.
 */
const password = z
  .string()
  .min(8, 'Use at least 8 characters.')
  .regex(/\p{L}/u, 'Include at least one letter.')
  .regex(/\d/, 'Include at least one number.')

const base = {
  name: z.string().trim().min(1, 'Enter a name.').max(120),
  email: z.email('Enter a valid email address.').max(255),
  role: z.enum(roles, { error: 'Choose a role.' }),
  status: z.enum(userStatuses),
}

export const createUserSchema = z.object({ ...base, password })

export const updateUserSchema = z.object({
  ...base,
  // Empty = keep the current password.
  password: z.union([z.literal(''), password]),
})

export type CreateUserValues = z.infer<typeof createUserSchema>
export type UpdateUserValues = z.infer<typeof updateUserSchema>
export type UserPayload = CreateUserValues
