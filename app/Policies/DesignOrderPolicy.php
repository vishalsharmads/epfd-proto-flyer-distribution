<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\DesignOrder;
use App\Models\User;

/** A business user may only see and change their own business's design orders; brief edits only while draft. */
class DesignOrderPolicy
{
    public function view(User $user, DesignOrder $order): bool
    {
        return $user->business_id !== null && $user->business_id === $order->business_id;
    }

    public function update(User $user, DesignOrder $order): bool
    {
        return $this->view($user, $order) && $order->status === 'draft';
    }

    public function delete(User $user, DesignOrder $order): bool
    {
        return $this->update($user, $order);
    }

    public function pay(User $user, DesignOrder $order): bool
    {
        return $this->update($user, $order);
    }
}
