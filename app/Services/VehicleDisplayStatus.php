<?php

namespace App\Services;

use App\Models\JobAssignment;
use App\Models\Vehicle;

class VehicleDisplayStatus
{
    /**
     * @return array{code: string, label: string, class: string}
     */
    public static function describe(Vehicle $vehicle, ?JobAssignment $assignment = null): array
    {
        $code = match ($assignment?->status) {
            JobAssignment::STATUS_EN_ROUTE => 'ON_ROAD',
            JobAssignment::STATUS_ARRIVED, JobAssignment::STATUS_IN_PROGRESS => 'AT_INCIDENT',
            JobAssignment::STATUS_ASSIGNED => 'ASSIGNED',
            default => self::operationalStatus($vehicle) ?? match ($vehicle->status) {
                Vehicle::STATUS_MAINTENANCE => 'OFF_ROAD',
                Vehicle::STATUS_AVAILABLE => 'IDLE',
                Vehicle::STATUS_DEPLOYED => 'ASSIGNED',
                default => 'UNKNOWN',
            },
        };

        return [
            'code' => $code,
            'label' => match ($code) {
                'ON_ROAD' => 'On Road / Moving',
                'OFF_ROAD' => 'Off Road',
                'ASSIGNED' => 'Assigned',
                'IDLE' => 'Idle / Free',
                'AT_INCIDENT' => 'At Incident / On Site',
                default => 'Status unavailable',
            },
            'class' => strtolower($code),
        ];
    }

    private static function operationalStatus(Vehicle $vehicle): ?string
    {
        $status = strtoupper((string) $vehicle->getAttribute('operational_status'));

        return in_array($status, ['ON_ROAD', 'OFF_ROAD'], true) ? $status : null;
    }
}
