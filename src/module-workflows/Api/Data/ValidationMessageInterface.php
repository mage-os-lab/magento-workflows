<?php
/**
 * Copyright (c) Mage-OS. Licensed under OSL-3.0.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageOS\Workflows\Api\Data;

/**
 * One save-time validation finding (docs/discovery/implementation/00-foundations.md, F2).
 *
 * Errors block the save; warnings travel with it (admin form messages, REST
 * validate responses, CLI import output). The code is a stable machine code
 * consumers may branch on; the message is the human-readable, translated text.
 * step_key/edge anchor the finding to the graph — the canvas pins messages to
 * nodes and the form anchors them to steps.
 *
 * @api
 */
interface ValidationMessageInterface
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    /**
     * 'error' or 'warning'
     */
    public function getSeverity(): string;

    /**
     * Stable machine code, e.g. GRAPH_CYCLE
     */
    public function getCode(): string;

    /**
     * Human-readable, translated message text
     */
    public function getMessage(): string;

    /**
     * Step key the finding targets; null for document-level findings
     */
    public function getStepKey(): ?string;

    /**
     * Edge name on the target step (e.g. on_false, case:eu); null when not edge-specific
     */
    public function getEdge(): ?string;
}
