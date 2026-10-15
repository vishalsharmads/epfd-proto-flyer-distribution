<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

/** A business user may only see and change their own business's campaigns; edits only while draft. */
class CampaignPolicy
{
    public function view(User $user, Campaign $campaign): bool
    {
        return $user->business_id !== null && $user->business_id === $campaign->business_id;
    }

    public function update(User $user, Campaign $campaign): bool
    {
        return $this->view($user, $campaign) && $campaign->isDraft();
    }

    public function delete(User $user, Campaign $campaign): bool
    {
        return $this->update($user, $campaign);
    }

    public function pay(User $user, Campaign $campaign): bool
    {
        return $this->update($user, $campaign);
    }
}
