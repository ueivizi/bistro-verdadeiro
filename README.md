# Bistrô Verdadeiro — Painel de Gorjetas

Atividade prática de Mineração de Dados / DSM: um sistema web com
`session_start()` seguro em PHP que traz no menu a funcionalidade de descobrir
a maior gorjeta de um bistrô através de um shell script.

O PHP nunca calcula a mineração — ele só chama o script em `scripts/mineracao.sh`
e formata o que volta. Quem lê o CSV, filtra e ordena é o shell, com `awk` e `sort`.

### Linux ou macOS

```bash
cd sistema-gorjetas
php -S localhost:8080
```

Abra `http://localhost:8080` no navegador.

### Windows

O sistema chama o shell script pelo `bash`. No Windows isso funciona com o
**Git Bash** instalado e com o `bash` disponível no PATH, ou rodando dentro do
**WSL**.

Se o `bash` não estiver disponível, o sistema **não trava**: ele percebe e
refaz os mesmos cálculos direto em PHP, avisando na tela que está usando o
caminho alternativo. Mas o shell script é a parte que a atividade pede, então
vale instalar o Git Bash (se tu tiver no Windows) ou rodar no linux terminal direto, para ver ele rodando de verdade.

Depois de instalar o Git Bash, acrescente `C:\Program Files\Git\bin` ao PATH do
usuário. Programas já abertos não enxergam a mudança: feche e reabra o terminal
(ou o XAMPP Control Panel) antes de testar.

### Feito no XAMPP / WAMP / hospedagem com Apache

Se forem testar, copiem a pasta `sistema-gorjetas` para dentro de `htdocs` (XAMPP) ou da raiz
do site, e acessem pelo endereço correspondente
(ex.: `http://localhost/sistema-gorjetas`).
No Linux, dê permissão de execução ao script uma vez, se for preciso:

```bash
chmod +x scripts/mineracao.sh
```

A pasta `var/` precisa ser gravável pelo servidor: é onde fica o contador de
tentativas de login. Ela é criada sozinha na primeira tentativa.

## Login

| Usuário | Senha       | Papel   |
|---------|-------------|---------|
| admin   | Fatec@2026  | Gerente |
| aluno   | Bistro#123  | Consulta |

As senhas ficam gravadas como hash (`password_hash`) em `config/config.php`,
nunca em texto puro. Para trocar ou criar uma senha nova:

```bash
php -r 'echo password_hash("suaSenhaNova", PASSWORD_BCRYPT), PHP_EOL;'
```

## Os dois papéis

O papel do usuário não é decorativo: ele é conferido no servidor, em toda
requisição, e define o que a pessoa consegue abrir.

**Gerente** — acesso completo. Vê as cinco análises, a página da base bruta e o
painel com a saída do shell script.

**Consulta** — vê apenas os números agregados do salão: o comparativo por dia da
semana e o resumo estatístico. Não vê mesas individuais (que trazem sexo do
cliente e se havia fumante à mesa), não abre a base bruta e não vê a saída do
shell, que expõe caminhos do servidor.

A restrição não é só esconder link do menu: `gorjetas.php` recusa uma operação
fora do papel mesmo se ela for digitada na URL, e `base.php` chama
`exigir_permissao('ver_base')` antes de desenhar qualquer coisa. Para mudar o
que cada papel enxerga, edite `PAPEIS` em `config/config.php`.

## Estrutura

```
sistema-gorjetas/
├── index.php              tela de login
├── autenticar.php         confere usuário/senha e abre a sessão
├── menu.php               menu de opções (tela inicial após o login)
├── gorjetas.php           chama a mineração e mostra o resultado
├── base.php               explica as colunas do CSV e mostra uma amostra
├── sair.php               encerra a sessão
├── config/
│   └── config.php         usuários, papéis, tempos de sessão, listas de permissão
├── includes/
│   ├── sessao.php         session_start seguro, CSRF, login, permissões
│   ├── tentativas.php     contador de tentativas de login, em arquivo
│   ├── mineracao.php      ponte PHP -> shell script (com plano B em PHP)
│   ├── topo.php           cabeçalho HTML das páginas internas
│   └── rodape.php         rodapé HTML
├── scripts/
│   └── mineracao.sh       mineração em shell (awk/sort) — o núcleo da atividade
├── dados/
│   └── gorjetas.csv       base de 244 atendimentos
├── var/                   contador de tentativas (criada em tempo de execução)
└── assets/
    ├── estilo.css
    └── logo.svg
```

## O shell script sozinho

Dá para rodar `mineracao.sh` direto no terminal, sem o PHP, para conferir
a mineração isoladamente:

```bash
cd sistema-gorjetas
./scripts/mineracao.sh -a dados/gorjetas.csv -o maior
./scripts/mineracao.sh -a dados/gorjetas.csv -o ranking -n 5 -d Sat
./scripts/mineracao.sh -a dados/gorjetas.csv -o dia -j
./scripts/mineracao.sh -h
```

Operações (`-o`): `maior`, `percentual`, `ranking`, `dia`, `resumo`.
Filtros: `-d` (Thur/Fri/Sat/Sun), `-p` (Lunch/Dinner), `-n` (linhas do
ranking). `-j` devolve JSON em vez de texto.

O arquivo precisa continuar com quebras de linha Unix (LF). Se for editado no
Windows e salvo como CRLF, o bash falha com um erro de `\r`.

## O que a parte de segurança da sessão cobre

- `session.use_strict_mode`, cookie `HttpOnly` e `SameSite=Lax`.
- `session_regenerate_id()` no login e em rotações periódicas — evita
  fixação de sessão.
- Timeout por inatividade (20 min) e tempo máximo de sessão (2 h).
- Token CSRF em todo formulário que muda algo (login, logout).
- Senhas guardadas como hash `bcrypt`, comparadas com `password_verify()`.
- Autorização por papel conferida no servidor a cada requisição.
- Toda saída passa por `htmlspecialchars()` antes de ir para o HTML.
- A chamada ao shell usa lista de permissão para operação/filtros e
  `escapeshellarg()` em cada argumento, então nada vindo da URL vira comando.

### Bloqueio de tentativas de login

O contador **não** fica na sessão. Se ficasse, bastaria descartar o cookie a
cada cinco tentativas para zerar o bloqueio e seguir tentando à vontade.

Ele fica em `var/tentativas.json`, gravado com `flock()` para não corromper
quando duas requisições chegam juntas, e é contado em duas frentes:

- **usuário + IP**: 5 erros bloqueiam aquele login por 2 minutos;
- **IP sozinho**: 15 erros bloqueiam o endereço inteiro por 5 minutos, o que
  fecha a saída de trocar de nome de usuário a cada tentativa.

Registros parados há mais de 15 minutos são descartados na leitura seguinte, e
um login bem-sucedido limpa os contadores daquele usuário e daquele IP.
