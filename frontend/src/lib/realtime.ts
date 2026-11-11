import { useEffect, useState } from 'react'
import type Echo from 'laravel-echo'
import { tenantApi } from '@/lib/api/client'

/*
 * Live updates over Reverb (Pusher protocol). Optional: without
 * VITE_REVERB_APP_KEY nothing connects, nothing extra is downloaded, and
 * pages keep polling. The Echo and Pusher libraries load on first use.
 *
 * Private channels are authorised by POST /api/v1/broadcasting/auth through
 * the normal API client, so the Sanctum session and CSRF handling are the
 * same as for every other request.
 */

type EchoClient = Echo<'reverb'>

let client: Promise<EchoClient | null> | undefined

function echo(): Promise<EchoClient | null> {
  client ??= connect()

  return client
}

async function connect(): Promise<EchoClient | null> {
  const key = import.meta.env.VITE_REVERB_APP_KEY
  if (!key) return null

  const [{ default: EchoClass }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')])
  const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'https'
  const port = Number(import.meta.env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80))

  return new EchoClass({
    broadcaster: 'reverb',
    Pusher,
    key,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: { auth: string } | null) => void) => {
        tenantApi
          .post<{ auth: string }>('/broadcasting/auth', { socket_id: socketId, channel_name: channel.name })
          .then((data) => {
            callback(null, data)
          })
          .catch((error: unknown) => {
            callback(error instanceof Error ? error : new Error('Channel authorisation failed.'), null)
          })
      },
    }),
  })
}

/**
 * Listens to one event on a private channel while mounted (channel null =
 * do not listen). Returns whether the subscription is live, so callers can
 * stop polling while it is.
 */
export function usePrivateChannel(channel: string | null, event: string, onEvent: () => void): boolean {
  const [live, setLive] = useState(false)

  useEffect(() => {
    if (channel === null) return

    let cancelled = false
    let leave: (() => void) | undefined

    void echo().then((connection) => {
      if (!connection || cancelled) return

      const subscription = connection.private(channel)
      subscription.subscribed(() => setLive(true))
      subscription.error(() => setLive(false))
      subscription.listen(`.${event}`, onEvent)
      leave = () => {
        subscription.stopListening(`.${event}`)
        connection.leave(channel)
      }
    })

    return () => {
      cancelled = true
      leave?.()
      setLive(false)
    }
  }, [channel, event, onEvent])

  return live
}
