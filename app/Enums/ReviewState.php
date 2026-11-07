<?php

namespace App\Enums;

/**
 * Review workflow states. See docs/blueprint/04-feature-specification.md §C1.
 */
enum ReviewState: string
{
    case Received = 'received';
    case AnalysisFailed = 'analysis_failed';
    case Analyzed = 'analyzed';
    case Drafting = 'drafting';
    case PendingApproval = 'pending_approval';
    case ScheduledAuto = 'scheduled_auto';
    case NeedsHumanWriting = 'needs_human_writing';
    case Publishing = 'publishing';
    case Published = 'published';
    case PublishFailed = 'publish_failed';
    case Rejected = 'rejected';
    case NoAction = 'no_action';
    case Stale = 'stale';

    /** States shown in the inbox "Needs attention" tab and counted in the sidebar badge. */
    public static function inboxAttention(): array
    {
        return [...self::needsAttention(), self::Received->value, self::Analyzed->value, self::Drafting->value];
    }

    /** States that represent work waiting for a person. */
    public static function needsAttention(): array
    {
        return [
            self::PendingApproval->value,
            self::NeedsHumanWriting->value,
            self::PublishFailed->value,
            self::Stale->value,
            self::AnalysisFailed->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Received',
            self::AnalysisFailed => 'Analysis failed',
            self::Analyzed => 'Analyzed',
            self::Drafting => 'Drafting',
            self::PendingApproval => 'Awaiting approval',
            self::ScheduledAuto => 'Scheduled',
            self::NeedsHumanWriting => 'Needs a human reply',
            self::Publishing => 'Publishing',
            self::Published => 'Published',
            self::PublishFailed => 'Publish failed',
            self::Rejected => 'Rejected',
            self::NoAction => 'No action',
            self::Stale => 'Review changed',
        };
    }
}
