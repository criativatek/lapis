<?php

namespace App\Support\Commercial;

use RuntimeException;

/**
 * Um checkout que não pode começar, com a razão já escrita em português para
 * quem a vai ler no ecrã.
 */
class CheckoutUnavailable extends RuntimeException {}
