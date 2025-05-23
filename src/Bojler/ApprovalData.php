<?php

namespace Bojler;

class ApprovalData
{
    public string $word;
    public ValidityInfo $validity_info;
    public bool $any;
    public bool $dictionary;
    public bool $wordlist;
    public bool $community;
    public bool $custom_reactions;
    public array $translations;
}
