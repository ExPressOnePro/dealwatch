<?php

namespace App\Services\Ai;

use RuntimeException;

/** ИИ-разбор — вспомогательная функция: любой сбой должен деградировать, а не ронять приложение. */
class AiException extends RuntimeException {}
