<?php declare(strict_types=1);

namespace Frosh\AltchaCaptcha\ScheduledTask;

use Frosh\AltchaCaptcha\Storefront\Framework\ChallengeReplayGuard;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskHandler;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(handles: CleanupUsedChallengesTask::class)]
class CleanupUsedChallengesTaskHandler extends ScheduledTaskHandler
{
    /**
     * @param EntityRepository<\Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTaskCollection> $scheduledTaskRepository
     */
    public function __construct(
        EntityRepository $scheduledTaskRepository,
        LoggerInterface $exceptionLogger,
        private readonly ChallengeReplayGuard $replayGuard,
    ) {
        parent::__construct($scheduledTaskRepository, $exceptionLogger);
    }

    public function run(): void
    {
        $this->replayGuard->purgeExpired();
    }
}
