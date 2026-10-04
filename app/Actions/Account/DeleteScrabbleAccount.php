<?php
declare(strict_types=1);

namespace App\Actions\Account;

/**
 * @author Dean Blackborough <dean@g3d-development.com>
 * @copyright Dean Blackborough (Costs to Expect) 2026
 * https://github.com/costs-to-expect/scrabble/blob/main/LICENSE
 */
class DeleteScrabbleAccount
{
    public function __invoke(
        string $bearer_token,
        string $resource_type_id,
        string $resource_id,
        string $user_id,
        string $email
    ): bool
    {
        \App\Jobs\DeleteScrabbleAccount::dispatch(
            $bearer_token,
            $resource_type_id,
            $resource_id,
            $user_id,
            $email
        )->delay(now()->addSeconds(5));

        return true;
    }
}
