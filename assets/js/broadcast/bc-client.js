/*
 * bc-client.js - cliente do protocolo do servico broadcast (bcastd), versao 1
 *
 * Uma unica conexao WebSocket leva controle (JSON) e midia (binario).
 * Eventos (EventTarget): 'open', 'close', 'message' (detail = objeto JSON),
 * 'media' (detail = ArrayBuffer), 'terminal' (detail = {state, reason}).
 */
(function (global) {
  'use strict';

  var TERMINAL_ERRORS = ['banned', 'room_not_open', 'room_not_found', 'room_locked',
    'bad_hello', 'invite_revoked', 'server_full', 'hello_timeout'];

  function uuid() {
    if (global.crypto && global.crypto.randomUUID) return global.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
      var r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  function BcClient(opts) {
    this.url = opts.url;
    this.roomToken = opts.roomToken;
    this.inviteToken = opts.inviteToken || '';
    this.name = opts.name || '';
    this.client = opts.client || 'web';
    this.ws = null;
    this.pending = {};
    this.backoff = 1000;
    this.stopped = false;
    this.connected = false;
    this.welcome = null;
    this.lastRx = 0;
    var et = document.createElement('span');
    this._et = et;
    this._pingTimer = null;
  }

  BcClient.prototype.on = function (type, fn) {
    this._et.addEventListener(type, function (ev) { fn(ev.detail); });
  };

  BcClient.prototype._emit = function (type, detail) {
    this._et.dispatchEvent(new CustomEvent(type, { detail: detail }));
  };

  BcClient.prototype.storageKey = function () {
    return 'bc_invite_' + this.roomToken;
  };

  BcClient.prototype.savedInvite = function () {
    try { return global.sessionStorage.getItem(this.storageKey()) || ''; } catch (e) { return ''; }
  };

  BcClient.prototype.connect = function () {
    var self = this;
    if (this.stopped) return;
    var ws;
    try {
      ws = new WebSocket(this.url);
    } catch (e) {
      this._scheduleReconnect();
      return;
    }
    ws.binaryType = 'arraybuffer';
    this.ws = ws;

    ws.onopen = function () {
      self.connected = true;
      self.backoff = 1000;
      self.lastRx = Date.now();
      var hello = { v: 1, t: 'hello', room_token: self.roomToken, name: self.name, client: self.client };
      var inv = self.inviteToken || self.savedInvite();
      if (inv) hello.invite_token = inv;
      ws.send(JSON.stringify(hello));
      self._emit('open', null);
      clearInterval(self._pingTimer);
      self._pingTimer = setInterval(function () {
        if (ws.readyState === 1) ws.send('{"v":1,"t":"ping"}');
      }, 10000);
    };

    ws.onmessage = function (ev) {
      self.lastRx = Date.now();
      if (typeof ev.data !== 'string') {
        self._emit('media', ev.data);
        return;
      }
      var m;
      try { m = JSON.parse(ev.data); } catch (e) { return; }
      self._handle(m);
    };

    ws.onclose = function () {
      self.connected = false;
      clearInterval(self._pingTimer);
      Object.keys(self.pending).forEach(function (k) {
        self.pending[k].reject(new Error('disconnected'));
      });
      self.pending = {};
      self._emit('close', null);
      self._scheduleReconnect();
    };

    ws.onerror = function () { /* onclose trata */ };
  };

  BcClient.prototype._scheduleReconnect = function () {
    var self = this;
    if (this.stopped) return;
    var wait = this.backoff;
    this.backoff = Math.min(this.backoff * 2, 10000);
    setTimeout(function () { self.connect(); }, wait);
  };

  BcClient.prototype._handle = function (m) {
    switch (m.t) {
      case 'welcome':
        this.welcome = m;
        break;
      case 'invite.token':
        try { global.sessionStorage.setItem(this.storageKey(), m.invite_token); } catch (e) { /* ignore */ }
        this.inviteToken = m.invite_token;
        break;
      case 'ack':
        if (m.ref && this.pending[m.ref]) {
          var p = this.pending[m.ref];
          delete this.pending[m.ref];
          clearTimeout(p.timer);
          p.resolve(m);
        }
        break;
      case 'goodbye':
        /* reinicio do servidor: reconecta sozinho; qualquer outro motivo encerra */
        if (m.reason === 'server_shutdown') break;
        this.stopped = true;
        this._emit('terminal', { state: m.state, reason: m.reason });
        break;
      case 'error':
        if (!this.welcome && TERMINAL_ERRORS.indexOf(m.code) >= 0) {
          this.stopped = true;
          if (m.code === 'bad_hello' || m.code === 'invite_revoked') {
            try { global.sessionStorage.removeItem(this.storageKey()); } catch (e) { /* ignore */ }
          }
          this._emit('terminal', { state: 'error', reason: m.code, msg: m.msg });
        }
        break;
    }
    this._emit('message', m);
  };

  BcClient.prototype.send = function (obj) {
    if (!this.ws || this.ws.readyState !== 1) return false;
    obj.v = 1;
    this.ws.send(JSON.stringify(obj));
    return true;
  };

  /* Comando com confirmacao (ack). Resolve com o ack; rejeita em timeout. */
  BcClient.prototype.cmd = function (type, fields) {
    var self = this;
    var id = uuid();
    var msg = Object.assign({ t: type, id: id }, fields || {});
    return new Promise(function (resolve, reject) {
      if (!self.send(msg)) { reject(new Error('offline')); return; }
      var timer = setTimeout(function () {
        delete self.pending[id];
        reject(new Error('timeout'));
      }, 8000);
      self.pending[id] = { resolve: resolve, reject: reject, timer: timer };
    });
  };

  BcClient.prototype.sendBinary = function (buf) {
    if (!this.ws || this.ws.readyState !== 1) return false;
    this.ws.send(buf);
    return true;
  };

  BcClient.prototype.buffered = function () {
    return this.ws ? this.ws.bufferedAmount : 0;
  };

  BcClient.prototype.close = function () {
    this.stopped = true;
    if (this.ws && this.ws.readyState === 1) {
      this.ws.send('{"v":1,"t":"bye"}');
      this.ws.close(1000, 'bye');
    }
  };

  global.BcClient = BcClient;
})(window);
