<?php
session_start();
require 'config.php';
require 'protecao.php';
if (empty($_SESSION['tenant_id'])) die('Tenant inválido');
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>Minhas Artes</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h4 class="mb-0">Minhas Artes</h4>
    <a class="btn btn-primary" href="designer_editor.php"><i class="me-2"></i>Nova arte</a>
  </div>

  <div id="grid" class="row g-3"></div>
</div>

<script>
async function api(url, opts={}) {
  const res = await fetch(url, opts);
  return res.json();
}

function card(item){
  const col = document.createElement('div');
  col.className = 'col-12 col-sm-6 col-md-4 col-lg-3';
  col.innerHTML = `
    <div class="card shadow-sm h-100">
      <div class="ratio ratio-1x1 bg-dark-subtle">
        <img src="${item.thumb_path || ''}" class="w-100 h-100 object-fit-cover" alt="">
      </div>
      <div class="card-body">
        <div class="fw-semibold text-truncate">${item.titulo}</div>
        <div class="text-muted small">${item.updated_at}</div>
        <div class="d-flex gap-2 mt-2">
          <a class="btn btn-sm btn-outline-primary w-100" href="designer_editor.php?id=${item.id}">Editar</a>
          <button class="btn btn-sm btn-outline-secondary w-100" onclick="dup(${item.id})">Duplicar</button>
        </div>
        <button class="btn btn-sm btn-outline-danger w-100 mt-2" onclick="del(${item.id})">Excluir</button>
      </div>
    </div>
  `;
  return col;
}

async function load(){
  const data = await api('list_designs.php');
  const grid = document.getElementById('grid');
  grid.innerHTML = '';
  (data.items || []).forEach(it => grid.appendChild(card(it)));
}

async function del(id){
  if (!confirm('Excluir esta arte?')) return;
  await api('delete_design.php', {
    method:'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({id})
  });
  load();
}

async function dup(id){
  const r = await api('duplicate_design.php', {
    method:'POST',
    headers: {'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({id})
  });
  if (r.ok) location.href = `designer_editor.php?id=${r.new_id}`;
}

load();
</script>
</body>
</html>
