<?php

namespace App\Enums;

enum CrewMovementAction: string
{
    case ApproveMobilisation = 'approve_mobilisation';
    case RecordArrival = 'record_arrival';
    case StartJoinStandby = 'start_join_standby';
    case SendToTraining = 'send_to_training';
    case CompleteTraining = 'complete_training';
    case MarkReady = 'mark_ready';
    case JoinVessel = 'join_vessel';
    case PlanSignoff = 'plan_signoff';
    case ConfirmDisembarkation = 'confirm_disembarkation';
    case StartDemobStandby = 'start_demob_standby';
    case TravelHome = 'travel_home';
    case TransferVessel = 'transfer_vessel';
    case Redeploy = 'redeploy';
    case CloseAssignment = 'close_assignment';
    case CancelAssignment = 'cancel_assignment';
    case CorrectMovement = 'correct_movement';

    public function label(): string
    {
        return match ($this) {
            self::ApproveMobilisation => 'Approve Mobilisation',
            self::RecordArrival => 'Record Arrival',
            self::StartJoinStandby => 'Start Join Standby',
            self::SendToTraining => 'Send to Training',
            self::CompleteTraining => 'Complete Training',
            self::MarkReady => 'Mark Ready',
            self::JoinVessel => 'Join Vessel',
            self::PlanSignoff => 'Plan Sign-off',
            self::ConfirmDisembarkation => 'Confirm Disembarkation',
            self::StartDemobStandby => 'Start Demobilisation Standby',
            self::TravelHome => 'Travel Home',
            self::TransferVessel => 'Transfer Vessel',
            self::Redeploy => 'Redeploy',
            self::CloseAssignment => 'Close Assignment',
            self::CancelAssignment => 'Cancel Assignment',
            self::CorrectMovement => 'Correct Movement',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
