# DNS for GitHub Pages

Zona `turnario.app` (decision A: the bare domain stays on Render for links, `.well-known` and passkeys):

| Tipo | Nome | Valore |
|---|---|---|
| CNAME | `www` | `turnario-app.github.io` |
| A | `@` | `216.24.57.1` (Render, unchanged) |

Verify the domain in the organization (Organization settings → Pages → Add a domain → `turnario.app`): GitHub shows a code; create a TXT record with **host** `_github-pages-challenge-turnario-app` (some providers want `_github-pages-challenge-turnario-app.turnario.app`) and the **code as value**. Never put it on `www`: a CNAME cannot share its name with other records and Render would deactivate the service records.
Remove `www.turnario.app` from the custom domains of the Render service `turnario-api` and `www.turnario.app` from `ALIAS_HOSTS`.

If the bare domain goes to Pages too (decision B): A `185.199.108.153`, `185.199.109.153`, `185.199.110.153`, `185.199.111.153` and AAAA `2606:50c0:8000::153`, `2606:50c0:8001::153`, `2606:50c0:8002::153`, `2606:50c0:8003::153`; the link pages of the API must first move to another host.

`turnario.eu` → `turnario.app` redirect stays on Render (`ALIAS_HOSTS`); Pages cannot redirect.
