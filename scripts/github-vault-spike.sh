#!/usr/bin/env bash
# Spike de validação do vault no GitHub (#438): mede V1–V8 contra um
# repositório de TESTE. Descartável; não é chamado pela aplicação.
#
# Nunca grave credenciais neste arquivo. Use variáveis de ambiente:
#   GH_TOKEN   token de instalação do App (ghs_...) ou fine-grained PAT (github_pat_...)
#   GH_REPO    owner/repo de um repositório privado de teste, vazio no início
#   GH_BRANCH  ramo (padrão: main)
#   GH_API     padrão: https://api.github.com
#
# Uso: GH_TOKEN=... GH_REPO=org/jotter-spike ./scripts/github-vault-spike.sh [v1|v2|v3|v4|v7|v8|all]
# Saída: cabeçalhos de cota e códigos HTTP de cada passo, para colar em
# docs/20261113-jotter-github-vault-spike.md.
set -euo pipefail

: "${GH_TOKEN:?defina GH_TOKEN}"
: "${GH_REPO:?defina GH_REPO (owner/repo de teste)}"
BRANCH="${GH_BRANCH:-main}"
API="${GH_API:-https://api.github.com}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

gh_call() { # método caminho [corpo-json] -> imprime status e cabeçalhos de cota; corpo em $TMP/body
  local method="$1" path="$2" data="${3:-}"
  local args=(-sS -o "$TMP/body" -D "$TMP/headers" -w '%{http_code}' -X "$method"
    -H "Authorization: Bearer $GH_TOKEN" -H 'Accept: application/vnd.github+json'
    -H 'X-GitHub-Api-Version: 2022-11-28')
  [[ -n "$data" ]] && args+=(-H 'Content-Type: application/json' --data "$data")
  [[ -n "${IF_NONE_MATCH:-}" ]] && args+=(-H "If-None-Match: $IF_NONE_MATCH")
  local code
  code="$(curl "${args[@]}" "$API$path")"
  printf '%s %s -> %s | %s\n' "$method" "$path" "$code" \
    "$(grep -iE '^x-ratelimit-(limit|remaining|used|resource)' "$TMP/headers" | tr -d '\r' | tr '\n' ' ')"
}
json() { php -r '$d=json_decode(file_get_contents($argv[1]),true); foreach(explode(".",$argv[2]) as $k){$d=$d[$k]??null;} echo is_scalar($d)?$d:json_encode($d);' "$TMP/body" "$1"; }

v7() { # PUT /contents num repositório vazio, ramo não padrão
  echo '== V7: primeiro arquivo num repositório vazio'
  gh_call GET "/repos/$GH_REPO/git/ref/heads/$BRANCH"
  gh_call PUT "/repos/$GH_REPO/contents/.gitkeep" "{\"message\":\"jotter: spike bootstrap\",\"content\":\"\",\"branch\":\"$BRANCH\"}"
}

v2() { # ref condicional
  echo '== V2: GET ref com If-None-Match'
  gh_call GET "/repos/$GH_REPO/git/ref/heads/$BRANCH"
  local etag; etag="$(grep -i '^etag:' "$TMP/headers" | cut -d' ' -f2- | tr -d '\r')"
  IF_NONE_MATCH="$etag" gh_call GET "/repos/$GH_REPO/git/ref/heads/$BRANCH"
  echo '   compare x-ratelimit-remaining/used das duas linhas: 304 gastou cota?'
}

v3() { # conteúdo inline em POST /git/trees
  echo '== V3: tamanho de content inline em POST /git/trees'
  gh_call GET "/repos/$GH_REPO/git/ref/heads/$BRANCH"
  local head; head="$(json object.sha)"
  for kb in 100 1024 5120 20480; do
    local content; content="$(head -c $((kb * 1024)) /dev/zero | tr '\0' 'a')"
    printf '{"base_tree":"%s","tree":[{"path":"spike-%s.md","mode":"100644","type":"blob","content":"%s"}]}' "$head" "$kb" "$content" > "$TMP/tree.json"
    gh_call POST "/repos/$GH_REPO/git/trees" "@$TMP/tree.json"
  done
}

v4() { # compare com mais de 300 arquivos
  echo '== V4: compare com mais de 300 arquivos'
  gh_call GET "/repos/$GH_REPO/git/ref/heads/$BRANCH"
  local base; base="$(json object.sha)"
  gh_call GET "/repos/$GH_REPO/git/commits/$base"
  local base_tree; base_tree="$(json tree.sha)"
  { printf '{"base_tree":"%s","tree":[' "$base_tree"
    for i in $(seq 1 320); do printf '%s{"path":"spike-many/%03d.md","mode":"100644","type":"blob","content":"# %d\\n"}' "$([[ $i -gt 1 ]] && echo ,)" "$i" "$i"; done
    printf ']}'; } > "$TMP/tree.json"
  gh_call POST "/repos/$GH_REPO/git/trees" "@$TMP/tree.json"; local tree; tree="$(json sha)"
  gh_call POST "/repos/$GH_REPO/git/commits" "{\"message\":\"jotter: spike 320 files\",\"tree\":\"$tree\",\"parents\":[\"$base\"]}"
  local commit; commit="$(json sha)"
  gh_call PATCH "/repos/$GH_REPO/git/refs/heads/$BRANCH" "{\"sha\":\"$commit\",\"force\":false}"
  gh_call GET "/repos/$GH_REPO/compare/$base...$commit"
  echo "   arquivos devolvidos pelo compare: $(php -r 'echo count(json_decode(file_get_contents($argv[1]),true)["files"]??[]);' "$TMP/body") (o Jotter usa a árvore recursiva; só confirmar o teto)"
}

v1() { # cota de escrita: quanto gasta um lote
  echo '== V1: cabeçalhos de cota antes e depois de um lote (blob + tree + commit + ref)'
  gh_call GET /rate_limit
  echo '   Rode o mesmo passo com tokens de DUAS instalações do mesmo App e compare x-ratelimit-used.'
  echo '   Se a segunda instalação já começar com o "used" da primeira, a cota é por App.'
}

v8() { # permissões devolvidas pelo GET do repositório
  echo '== V8: GET /repos com token de instalação traz "permissions"?'
  gh_call GET "/repos/$GH_REPO"
  echo "   private=$(json private) permissions=$(json permissions) default_branch=$(json default_branch)"
}

case "${1:-all}" in
  v1) v1 ;; v2) v2 ;; v3) v3 ;; v4) v4 ;; v7) v7 ;; v8) v8 ;;
  all) v8; v7; v2; v3; v4; v1 ;;
  *) echo "uso: $0 [v1|v2|v3|v4|v7|v8|all]" >&2; exit 2 ;;
esac
echo '-- V5 (token restrito a um repositório) e V6 (latência do webhook) dependem do App: ver o documento do spike.'
