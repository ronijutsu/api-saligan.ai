<?php

namespace App\Enums;

/**
 * Where a purchased top-up pack stands (ADR-010).
 *
 * A pack is created Pending before the customer is sent to the gateway, and
 * only Paid grants allowance. The distinction is what makes crediting
 * idempotent: the window is topped up on the transition *into* Paid, so a
 * replayed webhook finds the row already settled and does nothing.
 */
enum TopUpStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
