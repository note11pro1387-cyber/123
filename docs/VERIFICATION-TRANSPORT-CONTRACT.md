# Verification Transport Contract — W4-P3/W4-P4

W4-P4 consumes, and does not replace, the W4-P3 interface:

```php
interface VerificationTransportInterface
{
    public function deliver(VerificationDelivery $delivery): VerificationDeliveryResult;
}
```

- `VerificationDelivery` is ephemeral. The adapter reads `challengeId()`, `purpose()`, `channel()`, `destination()`, and `code()`. Never serialize/log the object or log its destination/code.
- `VerificationDeliveryResult::accepted()` is returned only after a positive provider acceptance response.
- `rejected(SAFE_CODE)` means a definite provider refusal.
- `unknown(SAFE_CODE)` means the provider may have accepted the message but the response was lost/invalid/uncertain.
- `notConfigured()` means valid provider configuration/runtime dependency is unavailable.

`CompositeVerificationTransport` routes solely by the server-created delivery's channel. The Telegram destination is resolved by W4-P3 from `registration_attempts.linked_telegram_chat_id`; the email destination is resolved from the Attempt's canonical email. Neither adapter reads a destination from the browser or uses the administrator chat as a fallback.

Only `accepted` lets W4-P3 activate the Challenge and start the exact 120-second TTL. A failure result must preserve the previous active Challenge. A process crash after provider acceptance can still leave ambiguous external state; exactly-once delivery is not claimed.

W4-P5 should reuse this interface, `TelegramBotApiClient`, `TelegramVerificationTransport`, `SmtpVerificationTransport`, and the typed result. It must add Recovery-purpose validation/authorization inside the shared Challenge service—not new provider adapters, another OTP implementation, or admin destination fallback.
