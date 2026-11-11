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
  // Create only: email an invitation instead of setting a password.
  invite: z.boolean(),
}

export const createUserSchema = z
  .object({ ...base, password: z.union([z.literal(''), password]) })
  .refine((v) => v.invite || v.password !== '', { path: ['password'], message: 'Set a password, or send an invitation.' })

export const updateUserSchema = z.object({
  ...base,
  // Empty = keep the current password.
  password: z.union([z.literal(''), password]),
})

export type CreateUserValues = z.infer<typeof createUserSchema>
export type UpdateUserValues = z.infer<typeof updateUserSchema>

/** What POST /users takes: a password, or `invite: true` without one. */
export type UserPayload = Omit<CreateUserValues, 'password' | 'invite'> & { password?: string; invite?: boolean }
