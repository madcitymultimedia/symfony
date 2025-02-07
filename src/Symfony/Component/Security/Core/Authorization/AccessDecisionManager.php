<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Core\Authorization;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Exception\InvalidArgumentException;

/**
 * AccessDecisionManager is the base class for all access decision managers
 * that use decision voters.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class AccessDecisionManager implements AccessDecisionManagerInterface
{
    public const STRATEGY_AFFIRMATIVE = 'affirmative';
    public const STRATEGY_CONSENSUS = 'consensus';
    public const STRATEGY_UNANIMOUS = 'unanimous';
    public const STRATEGY_PRIORITY = 'priority';

    private $voters;
    private $strategy;
    private $allowIfAllAbstainDecisions;
    private $allowIfEqualGrantedDeniedDecisions;

    /**
     * @param iterable|VoterInterface[] $voters                             An array or an iterator of VoterInterface instances
     * @param string                    $strategy                           The vote strategy
     * @param bool                      $allowIfAllAbstainDecisions         Whether to grant access if all voters abstained or not
     * @param bool                      $allowIfEqualGrantedDeniedDecisions Whether to grant access if result are equals
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(iterable $voters = [], string $strategy = self::STRATEGY_AFFIRMATIVE, bool $allowIfAllAbstainDecisions = false, bool $allowIfEqualGrantedDeniedDecisions = true)
    {
        $strategyMethod = 'decide'.ucfirst($strategy);
        if ('' === $strategy || !\is_callable([$this, $strategyMethod])) {
            throw new \InvalidArgumentException(sprintf('The strategy "%s" is not supported.', $strategy));
        }

        $this->voters = $voters;
        $this->strategy = $strategyMethod;
        $this->allowIfAllAbstainDecisions = $allowIfAllAbstainDecisions;
        $this->allowIfEqualGrantedDeniedDecisions = $allowIfEqualGrantedDeniedDecisions;
    }

    /**
     * @param bool $allowMultipleAttributes Whether to allow passing multiple values to the $attributes array
     *
     * {@inheritdoc}
     */
    public function decide(TokenInterface $token, array $attributes, mixed $object = null, bool $allowMultipleAttributes = false): bool
    {
        // Special case for AccessListener, do not remove the right side of the condition before 6.0
        if (\count($attributes) > 1 && !$allowMultipleAttributes) {
            throw new InvalidArgumentException(sprintf('Passing more than one Security attribute to "%s()" is not supported.', __METHOD__));
        }

        return $this->{$this->strategy}($token, $attributes, $object);
    }

    /**
     * Grants access if any voter returns an affirmative response.
     *
     * If all voters abstained from voting, the decision will be based on the
     * allowIfAllAbstainDecisions property value (defaults to false).
     */
    private function decideAffirmative(TokenInterface $token, array $attributes, mixed $object = null): bool
    {
        $deny = 0;
        foreach ($this->voters as $voter) {
            $result = $voter->vote($token, $object, $attributes);

            if (VoterInterface::ACCESS_GRANTED === $result) {
                return true;
            }

            if (VoterInterface::ACCESS_DENIED === $result) {
                ++$deny;
            } elseif (VoterInterface::ACCESS_ABSTAIN !== $result) {
                throw new \LogicException(sprintf('"%s::vote()" must return one of "%s" constants ("ACCESS_GRANTED", "ACCESS_DENIED" or "ACCESS_ABSTAIN"), "%s" returned.', get_debug_type($voter), VoterInterface::class, var_export($result, true)));
            }
        }

        if ($deny > 0) {
            return false;
        }

        return $this->allowIfAllAbstainDecisions;
    }

    /**
     * Grants access if there is consensus of granted against denied responses.
     *
     * Consensus means majority-rule (ignoring abstains) rather than unanimous
     * agreement (ignoring abstains). If you require unanimity, see
     * UnanimousBased.
     *
     * If there were an equal number of grant and deny votes, the decision will
     * be based on the allowIfEqualGrantedDeniedDecisions property value
     * (defaults to true).
     *
     * If all voters abstained from voting, the decision will be based on the
     * allowIfAllAbstainDecisions property value (defaults to false).
     */
    private function decideConsensus(TokenInterface $token, array $attributes, mixed $object = null): bool
    {
        $grant = 0;
        $deny = 0;
        foreach ($this->voters as $voter) {
            $result = $voter->vote($token, $object, $attributes);

            if (VoterInterface::ACCESS_GRANTED === $result) {
                ++$grant;
            } elseif (VoterInterface::ACCESS_DENIED === $result) {
                ++$deny;
            } elseif (VoterInterface::ACCESS_ABSTAIN !== $result) {
                throw new \LogicException(sprintf('"%s::vote()" must return one of "%s" constants ("ACCESS_GRANTED", "ACCESS_DENIED" or "ACCESS_ABSTAIN"), "%s" returned.', get_debug_type($voter), VoterInterface::class, var_export($result, true)));
            }
        }

        if ($grant > $deny) {
            return true;
        }

        if ($deny > $grant) {
            return false;
        }

        if ($grant > 0) {
            return $this->allowIfEqualGrantedDeniedDecisions;
        }

        return $this->allowIfAllAbstainDecisions;
    }

    /**
     * Grants access if only grant (or abstain) votes were received.
     *
     * If all voters abstained from voting, the decision will be based on the
     * allowIfAllAbstainDecisions property value (defaults to false).
     */
    private function decideUnanimous(TokenInterface $token, array $attributes, mixed $object = null): bool
    {
        $grant = 0;
        foreach ($this->voters as $voter) {
            foreach ($attributes as $attribute) {
                $result = $voter->vote($token, $object, [$attribute]);

                if (VoterInterface::ACCESS_DENIED === $result) {
                    return false;
                }

                if (VoterInterface::ACCESS_GRANTED === $result) {
                    ++$grant;
                } elseif (VoterInterface::ACCESS_ABSTAIN !== $result) {
                    throw new \LogicException(sprintf('"%s::vote()" must return one of "%s" constants ("ACCESS_GRANTED", "ACCESS_DENIED" or "ACCESS_ABSTAIN"), "%s" returned.', get_debug_type($voter), VoterInterface::class, var_export($result, true)));
                }
            }
        }

        // no deny votes
        if ($grant > 0) {
            return true;
        }

        return $this->allowIfAllAbstainDecisions;
    }

    /**
     * Grant or deny access depending on the first voter that does not abstain.
     * The priority of voters can be used to overrule a decision.
     *
     * If all voters abstained from voting, the decision will be based on the
     * allowIfAllAbstainDecisions property value (defaults to false).
     */
    private function decidePriority(TokenInterface $token, array $attributes, mixed $object = null)
    {
        foreach ($this->voters as $voter) {
            $result = $voter->vote($token, $object, $attributes);

            if (VoterInterface::ACCESS_GRANTED === $result) {
                return true;
            }

            if (VoterInterface::ACCESS_DENIED === $result) {
                return false;
            }

            if (VoterInterface::ACCESS_ABSTAIN !== $result) {
                throw new \LogicException(sprintf('"%s::vote()" must return one of "%s" constants ("ACCESS_GRANTED", "ACCESS_DENIED" or "ACCESS_ABSTAIN"), "%s" returned.', get_debug_type($voter), VoterInterface::class, var_export($result, true)));
            }
        }

        return $this->allowIfAllAbstainDecisions;
    }
}
