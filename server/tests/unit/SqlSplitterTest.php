<?php

declare(strict_types=1);

namespace Musique\Tests;

use Musique\Db\SqlSplitter;

final class SqlSplitterTest extends TestCase
{
    public function testPointVirguleDansLesChainesEtCommentaires(): void
    {
        $sql = "-- un ; commentaire\nCREATE TABLE a (x INT COMMENT 'un ; deux', y INT COMMENT 'l''été');\n"
            . "/* bloc ; */ INSERT INTO a VALUES (1, 2); # fin ; ligne\nSELECT \"x;y\";";
        $this->assertSame([
            "CREATE TABLE a (x INT COMMENT 'un ; deux', y INT COMMENT 'l''été')",
            'INSERT INTO a VALUES (1, 2)',
            'SELECT "x;y"',
        ], SqlSplitter::split($sql));
    }

    public function testDelimiterPourLesDeclencheurs(): void
    {
        $sql = "CREATE TABLE t (a INT);\nDELIMITER //\nCREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW\n    SET NEW.a = 1//\nDELIMITER ;\nSELECT 1;";
        $this->assertSame([
            'CREATE TABLE t (a INT)',
            "CREATE TRIGGER x BEFORE INSERT ON t FOR EACH ROW\n    SET NEW.a = 1",
            'SELECT 1',
        ], SqlSplitter::split($sql));
    }

    public function testSchemaComplet(): void
    {
        $statements = SqlSplitter::split((string) file_get_contents(\Musique\REPO_DIR . '/db/schema.sql'));
        $creates = count(array_filter($statements, static fn ($s) => str_starts_with($s, 'CREATE TABLE')));
        $triggers = count(array_filter($statements, static fn ($s) => str_starts_with($s, 'CREATE TRIGGER')));
        $this->assertSame(30, $creates, 'tables');
        $this->assertSame(4, $triggers, 'déclencheurs');
    }
}
