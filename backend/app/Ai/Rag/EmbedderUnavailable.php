<?php

namespace App\Ai\Rag;

use RuntimeException;

/** The embeddings model cannot be used now (switched off, not allowed for this data, or the call failed). */
class EmbedderUnavailable extends RuntimeException {}
