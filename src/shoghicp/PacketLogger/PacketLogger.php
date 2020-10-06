<?php

namespace shoghicp\PacketLogger;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo as Info;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\Server;
use pocketmine\utils\Utils;

class PacketLogger extends PluginBase implements Listener {

    /** @var resource[] */
    private $sessions = [];
    private $mode = true;
    private $logName = "{name}_{clientId}-{time}.log";
    private $nameSelector = [];
    private $clientIdSelector = [];
    private $ipSelector = [];

    private $packetIdFilter = [];
    private $packetIdDefault = true;

    public function onEnable() : void {
        $this->saveDefaultConfig();
        $config = $this->getConfig();
        $selectors = $config->get("selectors");

        if ($config->exists("logName")) {
            $this->logName = $config->get("logName");
        }

        $this->mode = isset($selectors["mode"]) ? (strtolower($selectors["mode"]) === "refuse" ? false : true) : true;

        if (isset($selectors["name"])) {
            foreach ($selectors["name"] as $name) {
                $this->nameSelector[strtolower($name)] = true;
            }
        }

        if (isset($selectors["clientId"])) {
            foreach ($selectors["clientId"] as $clientId) {
                $this->clientIdSelector[(int) $clientId] = true;
            }
        }

        if (isset($selectors["ip"])) {
            foreach ($selectors["ip"] as $ip) {
                $this->ipSelector[$ip] = true;
            }
        }

        $filters = $config->get("filters");
        if (isset($filters["packetId"])) {
            $this->packetIdDefault = (bool) $filters["packetId"]["default"];
            unset($filters["packetId"]["default"]);
            foreach ($filters["packetId"] as $packetId => $allow) {
                $this->packetIdFilter[(int) $packetId] = (bool) $allow;
            }
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function onDisable() : void {
        foreach ($this->getServer()->getOnlinePlayers() as $player) {
            if (isset($this->sessions[spl_object_hash($player)])) {
                $this->closeSession($player);
            }
        }
    }

    private function isPacketAllowed(DataPacket $packet) {
        if (isset($this->packetIdFilter[$packet->pid()])) {
            return $this->packetIdFilter[$packet->pid()];
        }
        return $this->packetIdDefault;
    }

    private function logInboundPacket(Player $player, DataPacket $packet) {
        if ($this->isPacketAllowed($packet)) {
            $packet->encode(); // other plugins might have changed the packet
            $header = "[Client -> Server 0x" . dechex($packet->pid()) . "] " . (new \ReflectionClass($packet))->getShortName() . " (length " . strlen($packet->buffer) . ")";
            $fields = $this->getFields($packet);
            $binary = trim(Utils::hexdump($packet->buffer));
            fwrite($this->sessions[spl_object_hash($player)], $header . PHP_EOL . $binary . PHP_EOL . $fields . PHP_EOL . PHP_EOL . PHP_EOL);
        }
    }

    private function logOutboundPacket(Player $player, DataPacket $packet) {
        if ($this->isPacketAllowed($packet)) {
            $packet->encode(); // needed :(
            $header = "[Server -> Client 0x" . dechex($packet->pid()) . "] " . (new \ReflectionClass($packet))->getShortName() . " (length " . strlen($packet->buffer) . ")";
            $fields = $this->getFields($packet);
            $binary = trim(Utils::hexdump($packet->buffer));
            fwrite($this->sessions[spl_object_hash($player)], $header . PHP_EOL . $binary . PHP_EOL . $fields . PHP_EOL . PHP_EOL . PHP_EOL);
        }
    }

    private static function safePrint($value, $spaces = 2) {
        if (is_object($value)) {
            if ((new \ReflectionClass($value))->hasMethod("__toString")) {
                $value = $value->__toString();
            } else {
                $value = get_class($value);
            }
        } elseif (is_string($value)) {
            if ($value === "") {
                $value = "(empty)";
            } elseif (preg_match('#([^\x20-\x7E])#', $value) > 0) {
                $value = "0x".bin2hex($value);
            }
        } elseif (is_array($value)) {
            $d = "Array:";
            foreach ($value as $key => $v) {
                $d .= PHP_EOL . str_repeat(" ", $spaces) . "$key: " . self::safePrint($v, $spaces + 1);
            }
            $value = $d;
        } else {
            $value = trim(str_replace("\n", "\n ", print_r($value, true)));
        }

        return $value;
    }

    private function getFields(DataPacket $packet) {
        $output = "";
        foreach ($packet as $key => $value) {
            if ($key === "buffer") {
                continue;
            }

            $output .= " $key: " . self::safePrint($value) . PHP_EOL;
        }

        return rtrim($output);
    }

    private function writeHeader($fp, $name, $clientId, Player $player) {
        fwrite($fp, "# Log generated by PacketLogger " . $this->getDescription()->getVersion()
            . " in PocketMine-MP " . $this->getServer()->getPocketMineVersion() . " for Minecraft: Bedrock Edition " . $this->getServer()->getVersion()
            . " (protocol #" . Info::CURRENT_PROTOCOL . ")" . PHP_EOL . PHP_EOL);
        fwrite($fp, "Player: $name, clientId $clientId from [/" . $player->getAddress() . ":" . $player->getPort() . PHP_EOL);
        fwrite($fp, "Start time: " . date("c") . PHP_EOL . PHP_EOL);
    }

    private function closeSession(Player $player) {
        fwrite($this->sessions[$i = spl_object_hash($player)], PHP_EOL . PHP_EOL . "End time: " . date("c"));
        fclose($this->sessions[$i]);
        unset($this->sessions[$i]);
    }

    /**
     * @param PlayerQuitEvent $event
     *
     * @priority NORMAL
     */
    public function onPlayerQuit(PlayerQuitEvent $event) {
        if (isset($this->sessions[spl_object_hash($event->getPlayer())])) {
            $this->closeSession($event->getPlayer());
        }
    }

    /**
     * @param DataPacketReceiveEvent $event
     *
     * @priority MONITOR
     * @ignoreCancelled true
     */
    public function onInboundPacket(DataPacketReceiveEvent $event) {
        $player = $event->getPlayer();
        $packet = $event->getPacket();
        if (isset($this->sessions[spl_object_hash($player)])) {
            $this->logInboundPacket($player, $packet);
        } elseif ($packet instanceof LoginPacket) {
            $name = strtolower($packet->username);
            $clientId = $packet->clientId;
            $ip = $player->getAddress();

            $match = false;
            if (isset($this->nameSelector[$name])) {
                $match = true;
            } elseif (isset($this->clientIdSelector[$clientId])) {
                $match = true;
            } elseif (isset($this->ipSelector[$ip])) {
                $match = true;
            }

            if ($match === $this->mode) {
                $path = $this->getDataFolder() . str_replace([
                        "{name}",
                        "{clientId}",
                        "{ip}",
                        "{time}"
                    ], [
                        $name,
                        $clientId,
                        $ip,
                        time()
                    ], $this->logName);
                $this->sessions[spl_object_hash($player)] = fopen($path, "w+b");
                $this->getLogger()->info("Logging packets from " . $player->getName() . "[/" . $player->getAddress() . ":" . $player->getPort() . "], clientId " . $clientId);
                $this->writeHeader($this->sessions[spl_object_hash($player)], $name, $clientId, $player);
                $this->logInboundPacket($player, $packet);
            }
        }
    }

    /**
     * @param DataPacketSendEvent $event
     *
     * @priority MONITOR
     * @ignoreCancelled true
     */
    public function onOutboundPacket(DataPacketSendEvent $event) {
        $player = $event->getPlayer();
        if (isset($this->sessions[spl_object_hash($player)])) {
            $this->logOutboundPacket($player, $event->getPacket());
        }
    }

}
