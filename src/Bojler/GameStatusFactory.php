<?php

namespace Bojler;

use DI\FactoryInterface;

class GameStatusFactory
{
    private readonly DatabaseHandler $db;
    private readonly PlayerHandler $player_handler;
    private readonly ConfigHandler $config;
    private readonly FactoryInterface $factory;
    # the injection should cover all injected dependencies of a GameStatus instance!
    public function __construct(DatabaseHandler $db, PlayerHandler $player_handler, ConfigHandler $config, FactoryInterface $factory)
    {
        $this->db = $db;
        $this->player_handler = $player_handler;
        $this->config = $config;
        $this->factory = $factory;
    }

    public function createInstace(GameManager $owner): GameStatus
    {
        return new GameStatus($owner, $this->db, $this->player_handler, $this->config, $this->factory);
    }
}
