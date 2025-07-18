<?php

declare (strict_types=1);
namespace Rector\FileSystem;

use RectorPrefix202507\Nette\Utils\Strings;
use Rector\Skipper\FileSystem\PathNormalizer;
use RectorPrefix202507\Symfony\Component\Filesystem\Filesystem;
use RectorPrefix202507\Webmozart\Assert\Assert;
/**
 * @see \Rector\Tests\FileSystem\FilePathHelperTest
 */
final class FilePathHelper
{
    /**
     * @readonly
     */
    private Filesystem $filesystem;
    /**
     * @see https://regex101.com/r/d4F5Fm/1
     * @var string
     */
    private const SCHEME_PATH_REGEX = '#^([a-z]+)\\:\\/\\/(.+)#';
    /**
     * @see https://regex101.com/r/no28vw/1
     * @var string
     */
    private const TWO_AND_MORE_SLASHES_REGEX = '#/{2,}#';
    /**
     * @var string
     */
    private const SCHEME_UNDEFINED = 'undefined';
    public function __construct(Filesystem $filesystem)
    {
        $this->filesystem = $filesystem;
    }
    public function relativePath(string $fileRealPath) : string
    {
        if (!$this->filesystem->isAbsolutePath($fileRealPath)) {
            return $fileRealPath;
        }
        return $this->relativeFilePathFromDirectory($fileRealPath, \getcwd());
    }
    /**
     * Used from
     * https://github.com/phpstan/phpstan-src/blob/02425e61aa48f0668b4efb3e73d52ad544048f65/src/File/FileHelper.php#L40, with custom modifications
     */
    public function normalizePathAndSchema(string $originalPath) : string
    {
        $directorySeparator = \DIRECTORY_SEPARATOR;
        $matches = Strings::match($originalPath, self::SCHEME_PATH_REGEX);
        if ($matches !== null) {
            [, $scheme, $path] = $matches;
        } else {
            $scheme = self::SCHEME_UNDEFINED;
            $path = $originalPath;
        }
        $normalizedPath = PathNormalizer::normalize((string) $path);
        $path = Strings::replace($normalizedPath, self::TWO_AND_MORE_SLASHES_REGEX, '/');
        $pathRoot = \strncmp($path, '/', \strlen('/')) === 0 ? $directorySeparator : '';
        $pathParts = \explode('/', \trim($path, '/'));
        $normalizedPathParts = $this->normalizePathParts($pathParts, $scheme);
        $pathStart = $scheme !== self::SCHEME_UNDEFINED ? $scheme . '://' : '';
        return PathNormalizer::normalize($pathStart . $pathRoot . \implode($directorySeparator, $normalizedPathParts));
    }
    private function relativeFilePathFromDirectory(string $fileRealPath, string $directory) : string
    {
        Assert::directory($directory);
        $normalizedFileRealPath = PathNormalizer::normalize($fileRealPath);
        $relativeFilePath = $this->filesystem->makePathRelative($normalizedFileRealPath, $directory);
        return \rtrim($relativeFilePath, '/');
    }
    /**
     * @param string[] $pathParts
     * @return string[]
     */
    private function normalizePathParts(array $pathParts, string $scheme) : array
    {
        $normalizedPathParts = [];
        foreach ($pathParts as $pathPart) {
            if ($pathPart === '.') {
                continue;
            }
            if ($pathPart !== '..') {
                $normalizedPathParts[] = $pathPart;
                continue;
            }
            /** @var string $removedPart */
            $removedPart = \array_pop($normalizedPathParts);
            if ($scheme !== 'phar') {
                continue;
            }
            if (\substr_compare($removedPart, '.phar', -\strlen('.phar')) !== 0) {
                continue;
            }
            $scheme = self::SCHEME_UNDEFINED;
        }
        return $normalizedPathParts;
    }
}
