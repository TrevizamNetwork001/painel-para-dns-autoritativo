<script>
setTimeout(function() {
    let aviso = document.createElement("div");

    aviso.innerHTML =
        "<strong>Sessão expirada</strong><br>" +
        "Você será redirecionado para a tela de login.";

    aviso.style.position = "fixed";
    aviso.style.top = "20px";
    aviso.style.right = "20px";
    aviso.style.background = "#7f1d1d";
    aviso.style.color = "#fecaca";
    aviso.style.padding = "15px";
    aviso.style.borderRadius = "8px";
    aviso.style.zIndex = "9999";
    aviso.style.boxShadow = "0 0 10px rgba(0,0,0,0.4)";

    document.body.appendChild(aviso);

    setTimeout(function() {
        window.location.replace("logout.php");
    }, 3000);

}, 900000);
</script>
