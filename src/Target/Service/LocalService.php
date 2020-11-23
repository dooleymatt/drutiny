<?php

namespace Drutiny\Target\Service;

use Drutiny\Target\TargetInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\InvalidArgumentException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Psr\Log\LoggerInterface;

class LocalService implements ExecutionInterface {

  protected $target;
  protected $isWin = FALSE;
  protected $cache;
  protected $logger;

  public function __construct(CacheInterface $cache, LoggerInterface $logger)
  {
    $this->cache = $cache;
    $this->logger = $logger;
  }

  public function setTarget(TargetInterface $target)
  {
    $this->target = $target;
    return $this;
  }

  /**
   * Run a local command.
   *
   * @param $cmd string
   *          The command you want to run.
   * @param $preProcess callable
   *          A callback to run to preprocess the output before caching.
   * @param $ttl string
   *          The time to live the processed result will line in cache.
   */
  public function run(string $cmd, callable $outputProcessor = NULL, int $ttl = 3600)
  {
    $this->logger->debug(__CLASS__ . ':LOOKUP: ' . $cmd);
    return $this->cache->get($this->getCacheKey($cmd), function ($item) use ($cmd, $ttl, $outputProcessor) {
      $item->expiresAfter($ttl);
      $this->logger->debug(__CLASS__ . ':MISS: ' . $cmd);

      $process = Process::fromShellCommandline($cmd, null, $this->getEnv());
      $process->setTimeout(600);
      try {
        $process->mustRun();
      }
      catch (ProcessFailedException $e) {
        $this->logger->error($e->getMessage());
        throw $e;
      }

      $output = $process->getOutput();
      $this->logger->debug($output);

      if (isset($outputProcessor)) {
        $output = $outputProcessor($output);
      }
      return $output;
    });
  }

  protected function getCacheKey($cmd)
  {
    return hash('md5', $this->replacePlaceholders($cmd));
  }

  protected function getEnv($envk = NULL)
  {
    if (!isset($this->target)) {
      return [];
    }
    $env = [];
    foreach ($this->target->getPropertyList() as $key) {
      $value = $this->target->getProperty($key);
      if (is_object($value) && !method_exists($key, '__toString')) {
        continue;
      }
      $var = strtoupper(str_replace('.', '_', $key));
      $env[$var] = $this->target->getProperty($key);
    }
    if ($envk === NULL) {
      return $env;
    }
    if (!isset($env[$envk])) {
      throw new InvalidArgumentException("No such environmental variable: '$envk'.");
    }
    return $env[$envk];
  }

  public function replacePlaceholders(string $commandline)
  {
      return preg_replace_callback('/\$([_a-zA-Z]++[_a-zA-Z0-9]*+)/', function ($matches) use ($commandline) {
          try {
            return $this->escapeArgument($this->getEnv($matches[1]));
          }
          catch (InvalidArgumentException $e) {
            // Leave the env variable in place if no variable is found.
            return '$'.$matches[1];
          }
      }, $commandline);
  }

  /**
   * Escapes a string to be used as a shell argument.
   */
  private function escapeArgument(?string $argument): string
  {
      if ('' === $argument || null === $argument) {
          return '""';
      }
      if ('\\' !== \DIRECTORY_SEPARATOR) {
          return "'".str_replace("'", "'\\''", $argument)."'";
      }
      if (false !== strpos($argument, "\0")) {
          $argument = str_replace("\0", '?', $argument);
      }
      if (!preg_match('/[\/()%!^"<>&|\s]/', $argument)) {
          return $argument;
      }
      $argument = preg_replace('/(\\\\+)$/', '$1$1', $argument);

      return '"'.str_replace(['"', '^', '%', '!', "\n"], ['""', '"^^"', '"^%"', '"^!"', '!LF!'], $argument).'"';
  }
}