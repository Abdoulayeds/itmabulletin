<?php
/** TEMPLATE PDF OFFICIEL ITMA **/

$logo = $CFG->wwwroot . '/local/itmabulletin/pix/logo-itma.png';

?>
<html>
<head>
<style>
body {
    font-family: DejaVu Sans, sans-serif;
    font-size: 12px;
}

.header {
    text-align: center;
    margin-bottom: 15px;
}

.header img {
    width: 120px;
}

.header-title {
    font-weight: bold;
    font-size: 14px;
    text-transform: uppercase;
}

.school-info {
    text-align: center;
    margin-top: 3px;
    margin-bottom: 10px;
}

.student-box {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
}

.student-box td {
    padding: 6px 8px;
    border: 1px solid #333;
}

.table-notes {
    width: 100%;
    border-collapse: collapse;
    margin-top: 18px;
}

.table-notes th {
    background: #003366;
    color: white;
    padding: 6px;
    border: 1px solid #000;
    text-align: center;
}

.table-notes td {
    padding: 5px;
    border: 1px solid #000;
    text-align: center;
}

.ue-row {
    background: #f2f2f2;
    font-weight: bold;
    text-align: left;
}

.footer-note {
    margin-top: 15px;
    font-size: 12px;
}

.signature {
    margin-top: 40px;
    width: 100%;
    text-align: center;
    font-size: 12px;
}

.signature td {
    padding-top: 40px;
}
</style>
</head>

<body>

<div class="header">
    <img src="<?php echo $logo; ?>">
    <div class="header-title">INSTITUT PRIVE AFRICAIN DE TECHNOLOGIE ET DE MANAGEMENT</div>
    <div class="school-info">
        ANNEE UNIVERSITAIRE 2024-2025<br>
        Bamako – Mali • Tel : 2281668 / 44215510 • Email : contact@itma-edu.ml
    </div>
</div>

<h3 style="text-align:center;">BULLETIN DE NOTES – ITMA</h3>

<table class="student-box">
    <tr>
        <td><b>Nom et prénom</b></td><td><?= $studentname ?></td>
        <td><b>Classe</b></td><td><?= $classe ?></td>
    </tr>
    <tr>
        <td><b>Semestre</b></td><td><?= $semester ?></td>
        <td><b>Année académique</b></td><td>2024-2025</td>
    </tr>
</table>

<table class="table-notes">
    <tr>
        <th>UE / Matière</th>
        <th>Crédit</th>
        <th>Note Classe</th>
        <th>Note Examen</th>
        <th>Moyenne</th>
        <th>Date Session</th>
    </tr>

    <?= $notes_html ?>
</table>

<div class="footer-note">
<b>Moyenne Générale :</b> <?= $semesteravg ?><br><br>
<b>NB :</b> La moyenne de validation de chaque UE doit être supérieure ou égale à 12
</div>

<table class="signature">
<tr>
    <td>Le Directeur Général</td>
    <td>Le Directeur Académique Adjoint</td>
</tr>
</table>

</body>
</html>
