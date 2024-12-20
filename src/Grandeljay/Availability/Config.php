<?php

namespace Grandeljay\Availability;

class Config
{
    private array $config;

    public function __construct()
    {
        $this->loadConfig();
    }

    private function loadConfig(): void
    {
        $filepathRelative     = 'discord-availability/config.json';
        $potentialConfigPaths = [];

        switch (PHP_OS) {
            case 'WINNT':
                $potentialConfigPaths = [
                    'config.json',
                    '$USERPROFILE/.config/' . $filepathRelative,
                    '$APPDATA/' . $filepathRelative,
                ];
                break;

            default:
                $potentialConfigPaths = [
                    'config.json',
                    '$HOME/.config/' . $filepathRelative,
                    '/etc/' . $filepathRelative,
                ];
                break;
        }

        foreach ($potentialConfigPaths as $potentialConfigPath) {
            $potentialConfigPath = $this->expandEnvVars($potentialConfigPath);

            if (file_exists($potentialConfigPath)) {
                $rawData    = file_get_contents($potentialConfigPath);
                $parsedData = json_decode($rawData, true, 2, JSON_THROW_ON_ERROR);
                $error      = $this->validateConfig($parsedData);

                if ($error) {
                    $msg = sprintf('Bad config.json at `%s`:' . PHP_EOL, $potentialConfigPath);
                    $msg = $msg . "  Error:       " . $error . PHP_EOL;
                    die($msg);
                }

                $normalisedCfg = $this->normaliseConfig($parsedData);
                $this->config  = $normalisedCfg;

                return;
            }
        }

        die('Missing config.json. Please refer to README.md.' . PHP_EOL);
    }

    /**
     * Processes the passed raw config and returns it in normalised form.
     *
     * Note: This function doesn't do any validation.
     *
     * @param array $config The raw config to normalise. This is essentially
     *                      just the decoded json string.
     *
     * @return array The normalised config.
     */
    private function normaliseConfig(array $rawConfig): array
    {
        $normalisedConfig = [];

        $normalisedConfig['maxAvailabilitiesPerUser'] = $rawConfig['maxAvailabilitiesPerUser'] ?? 100;
        $normalisedConfig['defaultDay']               = $rawConfig['defaultDay'] ?? "monday";
        $normalisedConfig['defaultTime']              = $rawConfig['defaultTime'] ?? "19:00";
        $normalisedConfig['eventName']                = $rawConfig['eventName'] ?? "Dota 2";
        $normalisedConfig['logLevel']                 = $rawConfig['logLevel'] ?? "Info";
        $normalisedConfig['timeZone']                 = $rawConfig['timeZone'] ?? \ini_get('date.timezone');

        $normalisedConfig['directoryAvailabilities'] = $this->extractAvailabilitiesDirFromConfig($rawConfig);
        $normalisedConfig['token']                   = $this->extractTokenFromConfig($rawConfig);

        return $normalisedConfig;
    }

    private function extractAvailabilitiesDirFromConfig(array $config): string
    {
        $valueFromConfig = $config['directoryAvailabilities'];
        $default         = '$HOME/.local/share/discord-availability/availabilities';

        return $this->normalisePathWithEnvVars($valueFromConfig ?? $default);
    }

    private function extractTokenFromConfig(array $validatedConfig): string
    {
        if (isset($validatedConfig['token'])) {
            return $validatedConfig['token'];
        }

        $path = $this->normalisePathWithEnvVars($validatedConfig['tokenFile']);

        $fileContents = file_get_contents($path);

        if (!$fileContents) {
            die('Failed to read token from file: ' . $path . PHP_EOL);
        }

        return trim($fileContents);
    }

    private function normalisePathWithEnvVars(string $path): string
    {
        $path = $this->expandEnvVars($path);
        $path = $this->normalisePath($path);

        return $path;
    }

    /**
     * Returns an absolute path.
     *
     * Unlike `realpath` this function also works on paths that don't point to
     * an existing file.
     *
     * Also, symlinks are not resolved.
     *
     * @param string $path
     *
     * @return string
     */
    private function normalisePath(string $path): string
    {
        if ($this->isPathAbsolute($path)) {
            return $path;
        }

        $cwd = getcwd();

        if (!$cwd) {
            die('Could not determine current working directory.' . PHP_EOL);
        }

        $segments     = [$cwd, $path];
        $absolutePath = implode(DIRECTORY_SEPARATOR, $segments);

        return $absolutePath;
    }

    /**
     * Returns whether `$path` is an absolute path.
     *
     * Examples of absolute paths:
     * - `/var/www/linux`
     * - `C:\Windows`
     * - `\\WindowsNetworkLocation`
     *
     * @param string $path
     *
     * @return bool `true` if the path is absolute, otherwise `false`.
     */
    private function isPathAbsolute(string $path): bool
    {
        // Note: A single backslash must be denoted as four `\` characters in a
        // `preg_match` regex.
        if (1 === preg_match('@^(/|[A-Z]:\\\\|\\\\\\\\)@', $path)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Validates the passed config and returns an error if it is invalid.
     *
     * @param array $config The config to validate.
     *
     * @return string|null A potential error that occurred.
     */
    private function validateConfig(array $config): ?string
    {
        $path = $this->extractAvailabilitiesDirFromConfig($config);
        if (!file_exists($path)) {
            $msg = 'The "directoryAvailabilities" directory does not exist.' . PHP_EOL;
            $msg = $msg . sprintf('  Specified:   "%s"' . PHP_EOL, $config['directoryAvailabilities']);
            $msg = $msg . sprintf('  Interpreted: "%s"' . PHP_EOL, $path);
            return $msg;
        }

        if (isset($config['token']) and isset($config['tokenFile'])) {
            return 'One of "token" or "tokenFile" must be set but both are set.';
        }

        if (!isset($config['token']) and !isset($config['tokenFile'])) {
            return 'One of "token" or "tokenFile" must be set but neither are set.';
        }

        if (isset($config['tokenFile'])) {
            $path = $this->normalisePathWithEnvVars($config['tokenFile']);

            if (!file_exists($path)) {
                $msg = 'The "tokenFile" file does not exist.' . PHP_EOL;
                $msg = $msg . sprintf('  Specified:   "%s"' . PHP_EOL, $config['tokenFile']);
                $msg = $msg . sprintf('  Interpreted: "%s"' . PHP_EOL, $path);
                return $msg;
            }
        }

        return null;
    }

    /**
     * Returns a value from the config.
     *
     * @param string $key     The value's key.
     * @param mixed  $default The default value to return when the key is not
     *                        found.
     *
     * @return mixed
     */
    private function get(string $key, mixed $default = null): mixed
    {
        if (isset($this->config[$key])) {
            return $this->config[$key];
        }

        return $default;
    }

    /**
     * Returns the Discord API token.
     *
     * @return string
     */
    public function getAPIToken(): string
    {
        return $this->get('token');
    }

    /**
     * Returns the path of the availabilities directory.
     *
     * @return string
     */
    public function getAvailabilitiesDir(): string
    {
        return $this->get('directoryAvailabilities');
    }

    private function expandEnvVars(string $path): string
    {
        preg_match_all('/\$([A-Z_]+)/', $path, $environmentMatches, PREG_SET_ORDER);

        foreach ($environmentMatches as $match) {
            if (isset($match[0], $match[1])) {
                $matchFull                = $match[0];
                $matchEnvironmentVariable = $match[1];
                $environmentVariable      = getenv($matchEnvironmentVariable);

                if (false === $environmentVariable) {
                    die(sprintf('Could not get value for environment variable "%s".', $matchEnvironmentVariable) . PHP_EOL);
                }

                $path = str_replace($matchFull, $environmentVariable, $path);
            }
        }

        return $path;
    }

    public function getMaxAvailabilitiesPerUser(): int
    {
        return $this->get('maxAvailabilitiesPerUser');
    }

    public function getDefaultTime(): string
    {
        return $this->get('defaultTime');
    }

    public function getDefaultDay(): string
    {
        return $this->get('defaultDay');
    }

    public function getDefaultDateTime(): string
    {
        $dateTime = $this->getDefaultDay() . ' ' . $this->getDefaultTime();

        return $dateTime;
    }

    public function getEventName(): string
    {
        return $this->get('eventName');
    }

    public function getLogLevel(): string
    {
        return $this->get('logLevel');
    }

    public function getTimeZone(): string
    {
        return $this->get('timeZone');
    }
}
