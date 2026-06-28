<?php

declare(strict_types=1);

namespace AgenticGraph\Vector\Contracts;

interface VectorStoreContract extends CollectionStoreContract, DocumentStoreContract, SearchStoreContract {}
