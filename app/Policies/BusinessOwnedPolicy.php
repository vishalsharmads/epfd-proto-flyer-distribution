<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Records that belong to one business (invoices, saved cards, saved areas, support threads):
 * a business user may only see and change their own business's records.
 */
class BusinessOwnedPolicy
{
    public function view(User $user, Model $record): bool
    {
        return $user->business_id !== null && $user->business_id === $record->getAttribute('business_id');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->view($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->view($user, $record);
    }
}
