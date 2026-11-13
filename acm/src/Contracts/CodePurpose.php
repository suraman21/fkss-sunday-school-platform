<?php
/**
 * ACM contracts (S2): activation-code purposes. Machine-enforced on
 * every redemption — a code can never satisfy another purpose.
 */

namespace SSMS\Acm\Contracts;

enum CodePurpose: string
{
    case Activation = 'activation';
    case DeviceAdd = 'device_add';
    case Reset = 'reset';
}
