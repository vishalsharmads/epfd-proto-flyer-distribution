<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PrintOrder;
use App\Models\User;

/** A business user may only see and change their own business's print orders; edits only while draft. */
class PrintOrderPolicy
{
    public function view(User $user, PrintOrder $order): bool
    {
        return $user->business_id !== null && $user->business_id === $order->business_id;
    }

    public function update(User $user, PrintOrder $order): bool
    {
        return $this->view($user, $order) && $order->status === 'draft';
    }

    public function delete(User $user, PrintOrder $order): bool
    {
        return $this->update($user, $order);
    }

    public function pay(User $user, PrintOrder $order): bool
    {
        return $this->update($user, $order);
    }
}
