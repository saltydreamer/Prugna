<?php

namespace App\Enums;

/**
 * Where an issued invoice is in the SDI flow.
 */
enum SdiStatus: string
{
    /** Being sent to the intermediary. */
    case Pending = 'pending';
    /** Accepted by the intermediary and forwarded to the SDI. */
    case Sent = 'sent';
    /** RC: delivered to the customer. */
    case Delivered = 'delivered';
    /** MC: not delivered, but issued and available in the customer's reserved area. */
    case NotDelivered = 'not_delivered';
    /** NS: rejected by the SDI, the invoice is not issued: correct and send again. */
    case Rejected = 'rejected';
    /** NE EC01: accepted by the public administration. */
    case Accepted = 'accepted';
    /** NE EC02: refused by the public administration. */
    case Refused = 'refused';
    /** DT: no answer from the public administration within 15 days. */
    case Expired = 'expired';
    /** The intermediary refused the file or could not be reached. */
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In invio',
            self::Sent => 'Inviata allo SDI',
            self::Delivered => 'Consegnata',
            self::NotDelivered => 'Non consegnata (nel cassetto fiscale)',
            self::Rejected => 'Scartata',
            self::Accepted => 'Accettata dalla PA',
            self::Refused => 'Rifiutata dalla PA',
            self::Expired => 'Decorrenza termini',
            self::Error => 'Invio non riuscito',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Sent => 'info',
            self::Delivered, self::Accepted, self::Expired => 'success',
            self::NotDelivered => 'warning',
            self::Rejected, self::Refused, self::Error => 'error',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending, self::Sent => 'mdi-send-clock-outline',
            self::Delivered, self::Accepted, self::Expired => 'mdi-check-circle-outline',
            self::NotDelivered => 'mdi-inbox-arrow-down-outline',
            self::Rejected, self::Refused, self::Error => 'mdi-alert-circle-outline',
        };
    }

    /**
     * The invoice can (and should) be sent again.
     */
    public function canResend(): bool
    {
        return in_array($this, [self::Rejected, self::Error], true);
    }

    /**
     * From an SDI notification type (RC, NS, MC, NE + esito, DT, AT).
     */
    public static function fromNotification(string $type, ?string $outcome = null): ?self
    {
        return match (strtoupper($type)) {
            'RC' => self::Delivered,
            'MC', 'AT' => self::NotDelivered,
            'NS' => self::Rejected,
            'DT' => self::Expired,
            'NE' => strtoupper((string) $outcome) === 'EC02' ? self::Refused : self::Accepted,
            'EC01' => self::Accepted,
            'EC02' => self::Refused,
            default => null,
        };
    }

    /**
     * From the "marking" of an invoice at Openapi (waiting, sent, quarantena, invoice-error, rejected,
     * delivered, delivered-pa, not-delivered, accepted-pa, rejected-pa, deadline-terms).
     */
    public static function fromMarking(?string $marking): ?self
    {
        return match (strtolower(trim((string) $marking))) {
            'waiting', 'sent', 'quarantena' => self::Sent,
            'invoice-error' => self::Error,
            'rejected' => self::Rejected,
            'delivered', 'delivered-pa' => self::Delivered,
            'not-delivered', 'non consegnata', 'not_delivered' => self::NotDelivered,
            'accepted-pa' => self::Accepted,
            'rejected-pa' => self::Refused,
            'deadline-terms' => self::Expired,
            default => null,
        };
    }

    /**
     * @return list<array{value: string, title: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $status) => ['value' => $status->value, 'title' => $status->label()], self::cases());
    }
}
