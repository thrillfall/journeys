<?php
namespace OCA\Journeys\Exception;

/**
 * Thrown when a journal / entry is not found OR is not owned by the acting user.
 * The two cases are deliberately indistinguishable to callers so ownership
 * cannot be probed via the API.
 */
class JournalNotFoundException extends \RuntimeException {}
