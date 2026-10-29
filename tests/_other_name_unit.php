<?php
/**
 * What may be typed beside Other, as a list of cases.
 *
 * Every refusal is something a student actually typed into those boxes while this was
 * being tested, and every acceptance is a name a paper could really be called: the
 * point of the file is that tightening one rule cannot quietly start refusing those.
 *
 *     php tests/_other_name_unit.php
 */
require_once __DIR__ . '/../config/core.php';

$ok = 0; $bad = 0;

function case_ok(string $name): void {
    global $ok, $bad;
    $problem = other_name_problem($name, 'manuscript');
    if ($problem === null) { $ok++; return; }
    $bad++;
    echo "REFUSED a real name: " . var_export($name, true) . "\n   -> $problem\n";
}

function case_no(string $name, string $why): void {
    global $ok, $bad;
    $problem = other_name_problem($name, 'manuscript');
    if ($problem !== null) { $ok++; return; }
    $bad++;
    echo "ACCEPTED $why: " . var_export($name, true) . "\n";
}

// ---- names a paper really has ------------------------------------------------------
case_ok('Policy Brief');
case_ok('Case Study');
case_ok('Feasibility Study');
case_ok('Technical Report');
case_ok('Capstone Project');
case_ok('White Paper');
case_ok('Systematic Review');
case_ok('Business Plan');
case_ok('Thesis');
case_ok('IMRAD');
case_ok('Chapter 5 Report');
case_ok('Project Proposal and Design');     // four words
case_ok('Pagsusuri ng Pag-aaral');          // Filipino, and a hyphen
case_ok('AI Readiness Assessment');
case_ok('Hi-Tech Study');                   // starts with a greeting word and is still a name

// ---- what the boxes were taking ----------------------------------------------------
case_no('', 'nothing');
case_no('   ', 'spaces');
case_no('5', 'no letters');
case_no('sdfsdfdsfsd', 'keyboard mashing');
case_no('adasdadsaddsd', 'keyboard mashing');
case_no('hi!dfdsfsdfsddsffsd', 'a greeting then mashing');
case_no('asdfghjkl', 'a run along the keyboard');
case_no('qwertyuiop', 'a run along the keyboard');
case_no('aaaaaa', 'one letter over and over');
case_no('hi', 'a greeting');
case_no('Hello', 'a greeting');
case_no('good morning', 'a greeting');
case_no('thanks', 'filler');
case_no('test', 'filler');
case_no('N/A', 'filler');
case_no('This is a study about the effect of bamboo', 'a sentence');
case_no('A study of the uses of bamboo trays', 'a sentence');

echo "\n$ok passed, $bad failed\n";
exit($bad === 0 ? 0 : 1);
