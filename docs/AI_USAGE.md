# AI Usage

## How AI was used

I used Claude throughout this project as a pairing partner for drafting migrations, models, service code, and explaining Laravel/PHP concepts I was less certain about (e.g. `foreignIdFor()` vs `foreignId()->constrained()`, native PHP enums, SOLID trade-offs). ChatGPT was also used for an initial pass on some models.

## AI mistakes I caught and corrected

- **Table naming**: ChatGPT recommended renaming the `inventory` table to `inventories` for "Laravel convention consistency." I verified this against Laravel's actual pluralization rules and found `inventory` is treated as an uncountable noun by Laravel's inflector — the recommendation was factually wrong. I kept the table singular.
- **Enum comparison bug**: after adding native PHP enum casts to `Shipment::status`, a job guard clause (`$shipment->status !== 'pending'`) silently always evaluated true, because it compared an enum object against a raw string with strict inequality. I diagnosed this myself by directly inspecting the runtime value (`get_class($shipment->status)`) rather than accepting a guess, and fixed the comparison to use the enum case.
- **Missing `HasFactory` trait**: repeated `BadMethodCallException: Call to undefined method X::factory()` errors across six models were resolved by systematically checking (via `grep -L "HasFactory" app/Models/*.php`) which models lacked the trait, rather than fixing them one at a time as each test failed.

## Engineering decisions I made personally

- The five open business-rule questions (reservation expiry, partial shipment handling, transfer-while-reserved, immediate locking, overselling prevention) were decided by me — AI presented the trade-off space for each, but the choices and their justification in `ARCHITECTURE.md` are mine.
- Chose pessimistic locking (`lockForUpdate()`) over optimistic locking for concurrency control, given the simplicity/correctness trade-off suited this challenge's scope.
- Chose to keep audit logging inline in `ReservationService` rather than extracting a separate logger class, as a deliberate SRP trade-off given the mandatory nature of audit trails in this domain — documented as a discussion point in `ARCHITECTURE.md` rather than treated as an oversight.
- Verified every piece of generated code manually via Tinker and automated tests before considering it complete, rather than assuming AI-generated code was correct.

## What differentiates this solution

Explicit separation of "operational state" tables (`inventory`, `reservations`, `shipments`) from "audit/event" tables (`inventory_movements`, `reservation_history`, `shipment_events`), documented as a deliberate architectural pattern rather than incidental table proliferation.
