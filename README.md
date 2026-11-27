# Bistrô Verdadeiro — Painel de Gorjetas

Atividade prática de Mineração de Dados / DSM: um sistema web com
`session_start()` seguro em PHP que traz no menu a funcionalidade de descobrir
a maior gorjeta de um bistrô através de um shell script.

O PHP nunca calcula a mineração — ele só chama o script em `scripts/mineracao.sh`
e formata o que volta. Quem lê o CSV, filtra e ordena é o shell, com `awk` e `sort`.
A saída crua do script fica à mostra na própria tela, junto do comando que a
gerou, para dar para conferir que a conta veio mesmo de lá.

Do mesmo jeito, a aba **Pandas** não é calculada em PHP: ela chama o programa
em `scripts/analise_pandas.py`, e quem agrupa, descreve e correlaciona é o
pandas. As figuras dessa aba são desenhadas pelo matplotlib no servidor e
entregues ao navegador como PNG. Se faltar Python na máquina, as duas telas
caem num plano B em PHP que dá os mesmos números e avisa na tela.

Para rodar tudo é preciso **PHP 8**, um **bash** (para a mineração) e
**Python 3 com pandas e matplotlib** (para a aba Pandas). Nenhum dos três é
obrigatório para o sistema subir — o que falta vira aviso, não erro.

### Linux ou macOS

```bash
cd sistema-gorjetas
php -S localhost:8080
```

Abra `http://localhost:8080` no navegador.

### Windows

O sistema chama o shell script pelo `bash`. Instale o **Git Bash** — e é só isso:
o caminho de instalação é procurado direto, sem depender do PATH.

Confiar no PATH não funcionaria. O Windows traz um `bash.exe` em
`C:\Windows\System32` que é o lançador do WSL, e ele vem **antes** do Git na
lista. Sem uma distro instalada, esse `bash` responde

```
execvpe(/bin/bash) failed: No such file or directory
```

e o sistema concluiria que não há shell nenhum — caindo no plano B em PHP com o
Git Bash instalado do lado. Por isso a procura é, nesta ordem:

```
%ProgramFiles%\Git\bin\bash.exe
%ProgramFiles%\Git\usr\bin\bash.exe
...
bash            (o PATH, que é o caminho certo no Linux e no macOS)
```

Cada candidato só é aceito depois de responder a um `echo ok`; existir no disco
não basta. Se o seu bash estiver fora desses lugares, preencha `CAMINHO_BASH`
em `config/config.php`.

Se nenhum responder o sistema **não trava**: refaz os mesmos cálculos em PHP e
avisa na tela, listando onde procurou. A tela de mineração mostra um selo
dizendo se o resultado veio do `bash` ou do plano B.

### Rodando no XAMPP sem copiar nada

Em vez de copiar a pasta para dentro de `htdocs` — o que faz o código do
repositório e o código que roda ficarem fora de sincronia — crie uma **junção**
(o atalho de pasta do Windows) apontando de `htdocs` para o clone. O Apache
segue a junção e serve direto daqui.

No `cmd` ou no PowerShell, **sem precisar de administrador**:

```
mklink /J "C:\xampp\htdocs\bistro-verdadeiro" "Z:\dev\bistro-verdadeiro"
```

Suba o Apache pelo XAMPP Control Panel (botão *Start* na linha do Apache) e
abra `http://localhost/bistro-verdadeiro`. O que for editado no clone vale na
hora, sem copiar nada de novo.

Para desfazer, `rmdir "C:\xampp\htdocs\bistro-verdadeiro"` — apaga só o atalho,
o clone fica intacto.

Servir a raiz do repositório significa que o Apache enxerga também o `.git`, o
`var/` e o `dados/`. Por isso existem os arquivos `.htaccess`: o da raiz devolve
404 para qualquer caminho oculto (`/.git/...`) e nega os arquivos de apoio; cada
pasta interna tem o seu, com `Require all denied`. Sem eles,
`http://localhost/bistro-verdadeiro/.git/config` responderia com o conteúdo real
e o histórico inteiro do código sairia pela web.

### Sem XAMPP, direto pelo PHP

```bash
cd bistro-verdadeiro
php -S localhost:8080
```

E abra `http://localhost:8080`. Serve para um teste rápido, mas o servidor
embutido do PHP ignora `.htaccess` — não deixe ele exposto para fora da máquina.

### Linux ou macOS

Dê permissão de execução ao script uma vez, se for preciso:

```bash
chmod +x scripts/mineracao.sh
```

A pasta `var/` precisa ser gravável pelo servidor: é onde ficam o contador de
tentativas de login e a cópia ativa da base. Ela é criada sozinha no primeiro uso.

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

**Gerente** — acesso completo. Vê as cinco análises, a aba de pandas inteira, a
página da base bruta e o painel com a saída do shell script e do programa em
Python.

**Consulta** — vê apenas os números agregados do salão: o comparativo por dia da
semana e o resumo estatístico. Não vê mesas individuais (que trazem sexo do
cliente e se havia fumante à mesa), não abre a base bruta e não vê a saída do
shell nem a do Python, que expõem caminhos do servidor. Na aba de pandas ele
agrupa por período, dia e tamanho de mesa, mas não por sexo nem por fumante, e
o `head()` — que mostra linha por linha — não aparece para ele.

A restrição não é só esconder link do menu: `gorjetas.php` recusa uma operação
fora do papel mesmo se ela for digitada na URL, e `base.php` chama
`exigir_permissao('ver_base')` antes de desenhar qualquer coisa. Para mudar o
que cada papel enxerga, edite `PAPEIS` em `config/config.php`.

## Estrutura

```
bistro-verdadeiro/
├── index.php              tela de login
├── autenticar.php         confere usuário/senha e abre a sessão
├── menu.php               menu de opções (tela inicial após o login)
├── gorjetas.php           chama a mineração e mostra o resultado
├── base.php               explica as colunas do CSV e mostra uma amostra
├── dados.php              a base inteira, com filtros, ordenação e paginação
├── analises.php           medidas sobre a base filtrada ou sobre valores digitados
├── graficos.php           barras, histograma e dispersão sobre o mesmo recorte
├── pandas.php             as análises em Python: groupby, describe e correlação
├── figura.php             entrega a figura do matplotlib como imagem
├── upload.php             envio de CSV, lançamento manual e volta ao original
├── sair.php               encerra a sessão
├── config/
│   └── config.php         usuários, papéis, tempos de sessão, listas de permissão
├── includes/
│   ├── sessao.php         session_start seguro, CSRF, login, permissões
│   ├── dados.php          forma da base, leitura, validação e filtros
│   ├── estatistica.php    medidas estatísticas e leitura de números digitados
│   ├── grafico.php        gráficos em SVG, gerados no PHP (sem biblioteca)
│   ├── fragmento.php      decide entre a página inteira e só o conteúdo
│   ├── importacao.php     leitura e conferência do CSV enviado
│   ├── tentativas.php     contador de tentativas de login, em arquivo
│   ├── mineracao.php      ponte PHP -> shell script (com plano B em PHP)
│   ├── pandas.php         ponte PHP -> programa em pandas (com plano B em PHP)
│   ├── topo.php           cabeçalho HTML das páginas internas
│   └── rodape.php         rodapé HTML
├── scripts/
│   ├── mineracao.sh       mineração em shell (awk/sort)
│   └── analise_pandas.py  agrupamentos, describe, correlação e figuras em pandas
├── dados/
│   └── gorjetas.csv       base de 304 atendimentos
├── var/                   estado de execução: tentativas de login e base ativa
└── assets/
    ├── painel.js          troca o conteúdo sem recarregar a moldura
    ├── graficos.js        dica de valor nas marcas dos gráficos
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
Filtros: `-d` (Mon/Tue/Wed/Thur/Fri/Sat/Sun), `-p` (Lunch/Dinner), `-n` (linhas do
ranking). `-j` devolve JSON em vez de texto.

O arquivo precisa continuar com quebras de linha Unix (LF). Se for editado no
Windows e salvo como CRLF, o bash falha com um erro de `\r`.

## As análises em pandas

A aba **Pandas** é o par em Python do shell script: o PHP não calcula nada
dela. Ele chama `scripts/analise_pandas.py`, recebe JSON e formata. Quem lê o
CSV, padroniza os nomes das colunas, agrupa, descreve e correlaciona é o
pandas; quem desenha as figuras é o matplotlib.

A tela traz, em ordem:

- **Período × valor médio da conta** — o agrupamento que a atividade pede,
  `df.groupby("periodo")["conta"].mean()`, com a diferença entre almoço e
  jantar em reais e em porcento.
- **Qualquer agrupamento, qualquer medida** — o mesmo `groupby` com as duas
  colunas à escolha: agrupar por período, dia da semana, sexo do cliente,
  fumante ou tamanho de mesa; medir conta, gorjeta, gorjeta sobre a conta,
  gorjeta por pessoa ou pessoas à mesa. São 25 combinações, não uma.
- **`describe()`** — contagem, média, desvio padrão, extremos e os três
  quartis de cada coluna numérica, mais o `head()` das primeiras linhas.
- **`corr()`** — a matriz de correlação entre as cinco grandezas numéricas,
  os pares mais fortes e as maiores gorjetas da base.
- **As figuras do matplotlib** — conta × gorjeta com reta de tendência,
  pessoas à mesa × gorjeta, a média por grupo em barras e o mapa de
  correlação.
- **A saída crua** do programa, junto do comando que a gerou — a mesma coisa
  que aparece rodando no terminal.

### Instalação

```bash
python3 -m pip install pandas matplotlib
```

No Debian ou Ubuntu dá para usar os pacotes da distribuição:

```bash
sudo apt update
sudo apt install python3-pandas python3-matplotlib
```

No Windows o python quase nunca está no PATH do Apache, mesmo estando no PATH
do terminal. Por isso ele é procurado primeiro pelos caminhos de instalação —
`%LOCALAPPDATA%\Programs\Python\Python3*`, `%LOCALAPPDATA%\Python\pythoncore-*`,
`%ProgramFiles%\Python3*`, `C:\Python3*` — e só depois pelo PATH. Cada
candidato só é aceito depois de importar o pandas de verdade: existir no disco
não basta, e um python sem pandas é pior do que nenhum, porque responderia ao
`--version` e falharia na primeira análise.

Se o seu python estiver fora desses lugares, preencha `CAMINHO_PYTHON` em
`config/config.php`.

Se nenhum responder, a tela **não quebra**: os mesmos agrupamentos são
refeitos em PHP e um selo avisa de onde vieram os números. O que se perde são
as figuras, que só o matplotlib desenha. Os dois caminhos dão o mesmo
resultado até o centavo — o desvio padrão é amostral (n−1) e os quartis usam
interpolação linear nos dois lados, que é o que o pandas faz.

### O programa sozinho

Dá para rodar `analise_pandas.py` direto no terminal, sem o PHP:

```bash
python3 scripts/analise_pandas.py -a dados/gorjetas.csv -o periodo
python3 scripts/analise_pandas.py -a dados/gorjetas.csv -o grupo -g dia_da_semana -m gorjeta
python3 scripts/analise_pandas.py -a dados/gorjetas.csv -o correlacao -j
python3 scripts/analise_pandas.py -a dados/gorjetas.csv -o tudo
python3 scripts/analise_pandas.py -a dados/gorjetas.csv -f dispersao -s figura.png
python3 scripts/analise_pandas.py -h
```

Operações (`-o`): `periodo`, `grupo`, `visao`, `correlacao`, `tudo`.
Agrupamentos (`-g`): `periodo`, `dia_da_semana`, `genero`, `fumante`,
`quantos_na_mesa`. Medidas (`-m`): `conta`, `gorjeta`, `percentual`,
`por_pessoa`, `quantos_na_mesa`. Figuras (`-f`): `dispersao`, `pessoas`,
`grupo`, `correlacao`, sempre acompanhadas de `-s arquivo.png`. `-j` devolve
JSON, que é o que o PHP consome.

### Gráfico na aula × gráfico na web

Na aula o `plt.show()` abre uma janela, e foi por isso que a máquina Linux com
interface gráfica entrou na história: um terminal puro não tem onde desenhar.
No servidor não existe tela nenhuma, então o programa usa o backend **`Agg`**
do matplotlib, que grava a figura direto num arquivo. Quem mostra é o
navegador.

O PNG nasce em `var/`, com nome sorteado por `random_bytes()` — nada do que
veio da URL entra no nome do arquivo. O `figura.php` manda os bytes e apaga o
arquivo na mesma requisição, então a figura nunca ganha endereço próprio:
`var/` é negada pelo `.htaccess`, e o único caminho até ela passa pelo login e
pela conferência de papel. Um PNG que sobre de uma requisição interrompida é
varrido na chamada seguinte.

### Por que não tem `sudo` aqui

O jeito mais curto de chamar Python do PHP é
`shell_exec("sudo python3 script.py $arquivo")`. Ele funciona e é um buraco:
`$arquivo` entra na linha de comando sem escapar, então um ponto-e-vírgula
vindo da URL vira um segundo comando — rodando como root.

Aqui a chamada é montada argumento por argumento, cada um com
`escapeshellarg()`, e operação, agrupamento, medida e figura saem de listas
fixas em `config/config.php`, conferidas antes de virarem argumento. O
`argparse` do Python confere de novo, com `choices`. E não há `sudo` nenhum: o
script só precisa ler um CSV e escrever um PNG numa pasta que o servidor já
pode escrever. Dar root a um processo que recebe argumento de fora é trocar
uma falha de leitura por uma falha de máquina inteira.

O Python é chamado com `-I`, que isola o interpretador: ele ignora o
`PYTHONPATH` e não põe a pasta do script no caminho de import, então um
arquivo chamado `json.py` largado ao lado do script não é carregado no lugar
da biblioteca padrão.

A saída volta como UTF-8 por decisão do próprio script
(`sys.stdout.reconfigure`). Sem isso, no Windows o `print` sai no code page do
console e o `json_decode()` do PHP recusa a resposta inteira — basta um acento
para quebrar o JSON.

## Filtro que não recarrega a página

Cada página interna embrulha o seu conteúdo num `<div id="conteudo">`. Numa
visita normal, `includes/topo.php` desenha a moldura inteira em volta dele;
quando o pedido traz o cabeçalho `X-Fragmento: 1`, só esse div é devolvido.
`assets/painel.js` intercepta os formulários e links marcados com `data-vivo`,
busca o fragmento e troca no lugar — o cabeçalho, o menu e o rodapé não são
refeitos, e a rolagem fica onde estava.

Vale para os filtros da mineração, da tabela de dados, das análises e dos
gráficos, mais a ordenação e a paginação. Os formulários do envio de dados
ficam de fora de propósito: eles **mudam** a base, e para uma mudança o
recarregamento com mensagem de confirmação é o comportamento certo.

Nada disso é a única forma de fazer as coisas. Os formulários continuam sendo
formulários com `method` e `action`: sem JavaScript, com um navegador antigo ou
se a rede falhar no meio, o clique volta a ser uma navegação comum e a tela
funciona igual. O JavaScript muda quanto HTML chega, nunca quem pode pedir o
quê — as conferências de login e de papel rodam antes, iguais nos dois caminhos.

## Os gráficos

Três figuras em SVG geradas no PHP, sem biblioteca: média por categoria em
barras, distribuição em histograma e a nuvem de conta contra gorjeta com reta
de mínimos quadrados.

As barras são **links de verdade** — `<a href>` dentro do SVG. Clicar numa
recorta o salão por ela, e clicar de novo desfaz; clicar numa faixa do
histograma deixa só aquela faixa nos três gráficos. Sem JavaScript isso navega
normalmente; com JavaScript a troca acontece sem recarregar.

`assets/graficos.js` desenha a dica de valor ao passar o mouse e ao chegar pelo
teclado. As barras têm `tabindex`; os pontos da nuvem, não — são centenas, e
cada um virar uma parada de tabulação tornaria a página impossível de
atravessar. Sem esse arquivo os gráficos continuam legíveis: cada barra traz o
valor escrito ao lado e embaixo de cada figura há a tabela equivalente.

## A base ativa e o envio de dados

São 304 atendimentos, em sete dias da semana. Os 244 primeiros são a base
clássica de gorjetas, que só cobre de quinta a domingo. Os 60 últimos —
segunda, terça e quarta — são **sintéticos**, acrescentados para o salão ter a
semana inteira: a conta e o tamanho da mesa de cada um foram sorteados das
próprias linhas reais, para a faixa de valores continuar a mesma, e o que muda
de propósito é a gorjeta proporcional. A segunda-feira rende perto de 23% da
conta contra uns 16% do resto da semana — é um padrão plantado, e é ele que as
análises desta base revelam.

`dados/gorjetas.csv` é a **semente**: versionada, e o sistema nunca escreve
nela. Na primeira leitura ele copia esse arquivo para `var/gorjetas-ativa.csv`
e passa a trabalhar sobre a cópia — é ela que recebe o que for enviado, e é ela
que o shell script lê. Por isso o repositório não fica sujo a cada venda
lançada, e o botão *Restaurar a base original* sempre tem para onde voltar.

O envio acontece em dois passos. O primeiro lê o arquivo e confere linha por
linha contra `COLUNAS_BASE`; o segundo grava. Entre os dois, a tela mostra a
tabela do que foi entendido e a lista das linhas recusadas com o motivo de cada
uma. O arquivo enviado **não é gravado em lugar nenhum**: ele é lido do
temporário do PHP e descartado, e o que entra na base são registros montados
pelo validador, não o conteúdo do arquivo. Com isso não existe arquivo de
usuário dentro da pasta servida pelo Apache.

Aceita vírgula, ponto-e-vírgula ou tabulação como separador (descobre sozinho),
arquivo com ou sem cabeçalho, nomes de coluna em português, valores em
português (`Sábado`, `Almoço`, `Mulher`, `Sim`) e decimal com vírgula. O que
fica gravado é sempre o valor canônico da lista.

Só o papel **gerente** envia dados.

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
- A chamada ao Python segue a mesma regra, com a lista de permissão em
  `config/config.php` e o `choices` do `argparse` conferindo de novo do outro
  lado. Roda sem `sudo`, com `-I` para isolar o interpretador, e o PNG que ela
  gera tem nome sorteado por `random_bytes()`, é servido uma vez e apagado.
- O arquivo enviado é conferido com `is_uploaded_file()`, tem teto de tamanho e
  de linhas conferido no servidor, e nunca é escrito em disco.
- A escrita na base acontece sob `flock()` exclusivo, e a contagem do teto é
  feita de dentro da trava, pelo mesmo descritor.

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
