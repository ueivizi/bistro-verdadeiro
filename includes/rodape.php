</main>

<footer class="rodape">
    <p>
        Sessão aberta às <?= h(date('H:i', (int) $_SESSION['criada_em'])) ?>.
        Encerra sozinha após <?= (int) (TEMPO_INATIVIDADE / 60) ?> minutos sem uso.
    </p>
    <p>Atividade de Mineração de Dados — Desenvolvimento de Software Multiplataforma</p>
</footer>

</body>
</html>
