# Published Static-Site Font Assets Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (- [ ]) syntax for tracking.

**Goal:** Bundle Source Serif 4 and IBM Plex Mono with every static workspace publication while preserving system-font fallback when a source asset is unavailable.

**Architecture:** Licensed WOFF2 files live with the existing self-hosted Inter files. The static publication stylesheet declares the two new font faces and the publish controller copies a fixed list of files into each site's fonts directory. Missing source files remain non-fatal because the CSS family stacks already end in Noto and system fallbacks.

**Tech Stack:** Laravel 12, PHP 8.3, PHPUnit, Blade static publishing, CSS, WOFF2, Docker Compose via scripts/jt.ps1.

## Global Constraints

- Scope is static workspace publications only; do not alter themes, identity tokens, or marketing pages.
- Include only licensed Source Serif 4 and IBM Plex Mono WOFF2 assets with their license notices. Never use Notion assets.
- Do not download fonts during publishing or add a CDN dependency.
- A missing source font must not fail publication; CSS continues to use its existing Noto and system fallbacks.
- Run PHP and tests only through Docker Compose via scripts/jt.ps1.
- Never commit .env, credentials, private keys, vendor, node_modules, public/build, or dist.

---

## File structure

| File | Responsibility |
| --- | --- |
| frontend/src/assets/fonts/source-serif-4-700.woff2 | Source Serif 4 bold subset for static h1 titles. |
| frontend/src/assets/fonts/ibm-plex-mono-400.woff2 | IBM Plex Mono regular subset for static code. |
| frontend/src/assets/fonts/SOURCE-SERIF-4-OFL.txt | Source Serif 4 license notice. |
| frontend/src/assets/fonts/IBM-PLEX-MONO-OFL.txt | IBM Plex Mono license notice. |
| resources/views/publish/publish.css | Static font-face declarations and fallback stacks. |
| app/Http/Controllers/WorkspacePublishController.php | Explicit static-publish font manifest and tolerant copying. |
| tests/Feature/WorkspacePublishTest.php | Coverage for copied assets, stylesheet references, and fallback. |

### Task 1: Make the publication asset contract explicit

**Files:**
- Modify: app/Http/Controllers/WorkspacePublishController.php
- Modify: tests/Feature/WorkspacePublishTest.php

**Interfaces:**
- Consumes: WorkspacePublishController::publish(Request $request, int $workspaceId): JsonResponse.
- Produces: WorkspacePublishController::publishFontAssets(string $siteDir): void, which copies the fixed font manifest without throwing for a missing source.

- [ ] **Step 1: Write a failing publish-output assertion**

Add this assertion to test_workspace_publish_compiles_static_html_pages:

~~~php
foreach ([
    'inter-400.woff2',
    'inter-600.woff2',
    'inter-700.woff2',
    'source-serif-4-700.woff2',
    'ibm-plex-mono-400.woff2',
] as $font) {
    $this->assertFileExists(storage_path("app/public/sites/main/fonts/{$font}"));
}
~~~

- [ ] **Step 2: Run the focused test to prove the gap**

Run:

~~~powershell
.\scripts\jt.ps1 test --filter WorkspacePublishTest
~~~

Expected: FAIL because the editorial and mono files are not in the published fonts directory.

- [ ] **Step 3: Implement the fixed manifest**

Replace the controller's WOFF2 glob with a method called after fontsDir is created:

~~~php
private function publishFontAssets(string $siteDir): void
{
    $fontSourceDir = base_path('frontend/src/assets/fonts');

    foreach ([
        'inter-400.woff2',
        'inter-600.woff2',
        'inter-700.woff2',
        'source-serif-4-700.woff2',
        'ibm-plex-mono-400.woff2',
    ] as $fontFile) {
        $this->copyPublishAsset($fontSourceDir.'/'.$fontFile, $siteDir.'/fonts/'.$fontFile);
    }
}
~~~

Invoke this method after creating the fonts directory. Preserve copyPublishAsset's existing early return when is_file($source) is false.

- [ ] **Step 4: Re-run the focused test**

Run:

~~~powershell
.\scripts\jt.ps1 test --filter WorkspacePublishTest
~~~

Expected: the only remaining failure is for the two new source files, proving the copy contract is ready for the assets.

- [ ] **Step 5: Commit the manifest and red expectation**

~~~powershell
git add app/Http/Controllers/WorkspacePublishController.php tests/Feature/WorkspacePublishTest.php
git commit -m "fix(publish): define static font manifest"
~~~

### Task 2: Add licensed assets and map them in the static stylesheet

**Files:**
- Create: frontend/src/assets/fonts/source-serif-4-700.woff2
- Create: frontend/src/assets/fonts/ibm-plex-mono-400.woff2
- Create: frontend/src/assets/fonts/SOURCE-SERIF-4-OFL.txt
- Create: frontend/src/assets/fonts/IBM-PLEX-MONO-OFL.txt
- Modify: resources/views/publish/publish.css
- Modify: tests/Feature/WorkspacePublishTest.php

**Interfaces:**
- Consumes: the Task 1 manifest and CSS URLs relative to publish.css.
- Produces: fonts/source-serif-4-700.woff2 and fonts/ibm-plex-mono-400.woff2 in every published site.

- [ ] **Step 1: Write failing CSS-reference assertions**

After reading the published CSS in the existing feature test, add:

~~~php
$this->assertStringContainsString("font-family: 'Source Serif 4'", $css);
$this->assertStringContainsString("url('fonts/source-serif-4-700.woff2')", $css);
$this->assertStringContainsString("font-family: 'IBM Plex Mono'", $css);
$this->assertStringContainsString("url('fonts/ibm-plex-mono-400.woff2')", $css);
~~~

- [ ] **Step 2: Run the focused test to prove the CSS gap**

Run:

~~~powershell
.\scripts\jt.ps1 test --filter WorkspacePublishTest
~~~

Expected: FAIL because publish.css has no Source Serif 4 or IBM Plex Mono font-face declaration.

- [ ] **Step 3: Add approved assets and declarations**

Obtain the official OFL-licensed WOFF2 subsets for Source Serif 4 bold and IBM Plex Mono regular, verify the included OFL text, and save them under the four exact filenames in the file structure table.

Add these declarations directly after the Inter declarations in publish.css:

~~~css
@font-face { font-family: 'Source Serif 4'; font-style: normal; font-weight: 700; font-display: swap; src: url('fonts/source-serif-4-700.woff2') format('woff2'); }
@font-face { font-family: 'IBM Plex Mono'; font-style: normal; font-weight: 400; font-display: swap; src: url('fonts/ibm-plex-mono-400.woff2') format('woff2'); }
~~~

Do not change the existing --font-editorial or --font-code declarations; their Noto, Georgia, and monospace values are the runtime fallback.

- [ ] **Step 4: Verify the static output**

Run:

~~~powershell
.\scripts\jt.ps1 test --filter WorkspacePublishTest
~~~

Expected: PASS; the copied stylesheet references both exact filenames and every manifest asset exists in the published fonts directory.

- [ ] **Step 5: Commit the self-hosted font surface**

~~~powershell
git add frontend/src/assets/fonts resources/views/publish/publish.css tests/Feature/WorkspacePublishTest.php
git commit -m "fix(publish): bundle editorial and mono fonts"
~~~

### Task 3: Prove missing-source fallback and run regression verification

**Files:**
- Modify: tests/Feature/WorkspacePublishTest.php

**Interfaces:**
- Consumes: copyPublishAsset(string $source, string $destination): void, whose is_file guard defines the non-fatal fallback.
- Produces: a regression test showing a missing mono source does not turn publication into an error.

- [ ] **Step 1: Write the fallback feature test**

Add test_workspace_publish_falls_back_when_a_font_source_is_unavailable. It shall create its own authorized workspace with slug fallback, rename the source asset to a temporary .missing-for-test filename, perform the publish request, and restore the source in finally:

~~~php
$source = base_path('frontend/src/assets/fonts/ibm-plex-mono-400.woff2');
$temporarySource = $source.'.missing-for-test';

$this->assertFileExists($source);
rename($source, $temporarySource);

try {
    $response = $this->actingAs($admin)->postJson("/api/workspaces/{$workspace->id}/publish");

    $response->assertOk();
    $this->assertFileExists(storage_path('app/public/sites/fallback/index.html'));
    $this->assertFileDoesNotExist(
        storage_path('app/public/sites/fallback/fonts/ibm-plex-mono-400.woff2')
    );
} finally {
    rename($temporarySource, $source);
}
~~~

The test constructs its tenant, user, vault fixture, and workspace independently, matching the setup of the existing first publication test.

- [ ] **Step 2: Make the fallback test fail temporarily**

In the uncommitted working tree only, replace the is_file early return in copyPublishAsset with a RuntimeException, then run:

~~~powershell
.\scripts\jt.ps1 test --filter test_workspace_publish_falls_back_when_a_font_source_is_unavailable
~~~

Expected: FAIL with a publish request error. Revert the temporary strict guard immediately after observing the failure.

- [ ] **Step 3: Keep the tolerant behavior and prove it passes**

Restore and retain:

~~~php
if (! is_file($source)) {
    return;
}
~~~

Run:

~~~powershell
.\scripts\jt.ps1 test --filter test_workspace_publish_falls_back_when_a_font_source_is_unavailable
~~~

Expected: PASS; the static page exists and only the unavailable WOFF2 file is absent.

- [ ] **Step 4: Run feature and complete-suite verification**

Run:

~~~powershell
.\scripts\jt.ps1 test --filter WorkspacePublishTest
.\scripts\jt.ps1 test
~~~

Expected: both commands exit 0. Unrelated frontend warning backlog work is out of scope.

- [ ] **Step 5: Check and commit the regression test**

~~~powershell
git diff --check main...HEAD
git add tests/Feature/WorkspacePublishTest.php
git commit -m "test(publish): cover missing font fallback"
~~~

## Plan self-review

- **Spec coverage:** Tasks 1–3 respectively cover the explicit manifest, licensed Source Serif 4 and IBM Plex Mono assets plus CSS references, and non-fatal fallback with Docker-only verification.
- **Placeholder scan:** All paths, filenames, function names, assertions, commands, expected results, and commits are explicit.
- **Type consistency:** publishFontAssets(string $siteDir): void and copyPublishAsset(string $source, string $destination): void are used consistently. Every CSS URL, manifest item, and expected copied filename is identical.

