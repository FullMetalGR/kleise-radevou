<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Application\Dto\Customer;
use App\Domain\Audit\Actor;

/**
 * Who did something to a booking, as a notice to the business says it: which shape of actor, and
 * the name the sentence for that shape names.
 *
 * A tenant has several people, and the one who clicked is not the only one who was expecting the
 * customer, so a notice about a cancellation or a move says who did it. The trail names that
 * person by id alone, because a trail must carry nothing erasure has to reach; the id is turned
 * back into a name here, where the tenant's users may be read, and only for a message to the
 * people that user works with.
 *
 * Five shapes of actor, ActorShape, each one sentence of the notice's own, true of the case it is
 * printed for:
 *
 * - the customer, by name, since a name is how the business recognises whose morning changed;
 * - a user, by the name on their central row, read now rather than at the moment of the act;
 * - a user the tenant no longer has, said as that rather than as a number nobody can place;
 * - the platform's support acting in a session, said as the platform rather than by the
 *   operator's name, since the operator is nobody the business works with: the
 *   public name the caller hands in, config('site.name'), as the sentence's :name;
 * - the engine acting on its own, or a job, which is nobody at the business.
 *
 * It answers a shape and a name and no words: the notice reads the sentence for the shape in its
 * recipient's language, business-mail.php's cancellation_notice.by and move_notice.by, with the
 * name filled in; so the answer is read once per notice and holds for every recipient, whichever
 * language each reads.
 *
 * The users are read only for a user actor. The customer, support and the engine need no
 * lookup, and the notice that asks this has already read the list once for its recipients; a
 * second read is spent only where a name has to come from it.
 */
final readonly class WhoActed
{
    private function __construct(
        public ActorShape $shape,
        public ?string $name,
    ) {}

    /**
     * @param  string  $platform  the public name the platform's support is said in
     */
    public static function of(Actor $actor, Customer $customer, BusinessDirectory $directory, string $platform): self
    {
        if ($actor->isCustomer()) {
            return new self(ActorShape::Customer, $customer->name);
        }

        if ($actor->supportOperatorId() !== null) {
            return new self(ActorShape::Support, $platform);
        }

        $userId = $actor->userId();

        if ($userId === null) {
            return new self(ActorShape::System, null);
        }

        $user = TenantUser::among($directory->users(), $userId);

        return $user === null ? new self(ActorShape::RemovedUser, null) : new self(ActorShape::User, $user->name);
    }

    /**
     * What the shape's sentence names, filled from the name: the customer's as :customer, a
     * user's as :staff, the platform's public name as :name, and nothing for a removed user and
     * the system, whose sentences name nobody.
     *
     * @return array<string, string>
     */
    public function names(): array
    {
        return match ($this->shape) {
            ActorShape::Customer => ['customer' => (string) $this->name],
            ActorShape::User => ['staff' => (string) $this->name],
            ActorShape::Support => ['name' => (string) $this->name],
            ActorShape::RemovedUser, ActorShape::System => [],
        };
    }
}
