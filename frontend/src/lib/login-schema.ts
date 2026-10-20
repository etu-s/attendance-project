import { z } from 'zod'

// Mirrors App\Modules\Core\Http\Requests\LoginRequest.
export const loginSchema = z.object({
  email: z.email('Enter a valid email address.').max(255),
  password: z.string().min(1, 'Enter your password.').max(255),
})

export type LoginValues = z.infer<typeof loginSchema>
