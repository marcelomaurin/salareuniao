/*
 * bc-media.js - publicacao (orador) e reproducao (ouvintes) do broadcast
 *
 * Orador: MediaRecorder gera WebM (VP8/VP9 + Opus) em pedacos de chunk_ms;
 * cada pedaco vai num quadro binario com cabecalho de 16 bytes:
 *   [0]=0xB5 [1]=tipo [2]=flags [3]=geracao [4..7]=seq [8..15]=timestamp ms
 * Ouvintes: MediaSource Extensions (ManagedMediaSource no Safari/iOS 17+).
 */
(function (global) {
  'use strict';

  var MAGIC = 0xB5;

  function header(kind, gen, seq) {
    var h = new ArrayBuffer(16);
    var v = new DataView(h);
    var ts = Date.now();
    v.setUint8(0, MAGIC);
    v.setUint8(1, kind);
    v.setUint8(2, 0);
    v.setUint8(3, gen & 0xFF);
    v.setUint32(4, seq >>> 0);
    v.setUint32(8, Math.floor(ts / 4294967296));
    v.setUint32(12, ts >>> 0);
    return new Uint8Array(h);
  }

  function pickMime() {
    var opts = ['video/webm;codecs=vp8,opus', 'video/webm;codecs=vp9,opus', 'video/webm'];
    if (!global.MediaRecorder) return '';
    for (var i = 0; i < opts.length; i++) {
      if (MediaRecorder.isTypeSupported(opts[i])) return opts[i];
    }
    return '';
  }

  /* ------------------------------------------------------------ orador */

  function BcPublisher(client, opts) {
    this.client = client;
    this.chunkMs = opts.chunkMs || 250;
    this.profile = { w: 640, h: 360, fps: 24, kbps: 500 };
    this.source = 'camera';
    this.stream = null;
    this.recorder = null;
    this.gen = 0;
    this.seq = 0;
    this.muted = false;
    this.mime = '';
    this.onPreview = opts.onPreview || function () {};
    this.onError = opts.onError || function () {};
    this._chain = Promise.resolve();
  }

  BcPublisher.supported = function () {
    return !!(global.MediaRecorder && navigator.mediaDevices && pickMime());
  };

  BcPublisher.prototype._capture = async function () {
    var p = this.profile;
    var audio = { echoCancellation: true, noiseSuppression: true, autoGainControl: true };
    if (this.source === 'screen') {
      var disp = await navigator.mediaDevices.getDisplayMedia({ video: { frameRate: p.fps }, audio: false });
      var mic = null;
      try { mic = await navigator.mediaDevices.getUserMedia({ audio: audio, video: false }); } catch (e) { mic = null; }
      var tracks = disp.getVideoTracks();
      if (mic) tracks = tracks.concat(mic.getAudioTracks());
      var self = this;
      disp.getVideoTracks()[0].addEventListener('ended', function () {
        if (self.source === 'screen') self.switchSource('camera');
      });
      return new MediaStream(tracks);
    }
    return navigator.mediaDevices.getUserMedia({
      audio: audio,
      video: { width: { ideal: p.w }, height: { ideal: p.h }, frameRate: { ideal: p.fps } }
    });
  };

  BcPublisher.prototype._stopTracks = function () {
    if (this.stream) this.stream.getTracks().forEach(function (t) { t.stop(); });
    this.stream = null;
  };

  BcPublisher.prototype._stopRecorder = function () {
    var r = this.recorder;
    this.recorder = null;
    if (r && r.state !== 'inactive') {
      try { r.stop(); } catch (e) { /* ignore */ }
    }
  };

  BcPublisher.prototype._startRecorder = function (gen) {
    var self = this;
    var myGen = gen & 0xFF;
    var rec;
    this.mime = pickMime();
    rec = new MediaRecorder(this.stream, {
      mimeType: this.mime,
      videoBitsPerSecond: this.profile.kbps * 1000,
      audioBitsPerSecond: 64000
    });
    this.gen = myGen;
    this.seq = 0;
    rec.ondataavailable = function (ev) {
      if (!ev.data || !ev.data.size || self.recorder !== rec) return;
      var blob = ev.data;
      /* encadeia para manter a ordem dos pedacos */
      self._chain = self._chain.then(function () {
        return blob.arrayBuffer();
      }).then(function (ab) {
        if (self.recorder !== rec) return;
        var h = header(self.seq === 0 ? 1 : 2, myGen, self.seq++);
        var out = new Uint8Array(16 + ab.byteLength);
        out.set(h, 0);
        out.set(new Uint8Array(ab), 16);
        self.client.sendBinary(out.buffer);
        if (self.client.buffered() > 8 * 1024 * 1024) {
          /* rede do orador nao acompanha: reinicia com nova geracao em menor qualidade */
          self.onError('rede lenta: reduzindo a qualidade');
          self.profile = { w: 640, h: 360, fps: 15, kbps: 350 };
          self.restart((self.gen + 1) & 0xFF);
        }
      }).catch(function () { /* ignore */ });
    };
    this.recorder = rec;
    return rec;
  };

  /* Inicia a transmissao na geracao indicada pelo servidor. */
  BcPublisher.prototype.start = async function (gen, profile) {
    if (profile) this.profile = profile;
    if (!this.stream) this.stream = await this._capture();
    this.setMuted(this.muted);
    this.onPreview(this.stream);
    var rec = this._startRecorder(gen);
    var ack = await this.client.cmd('media.init', {
      mime: this.mime, gen: this.gen, w: this.profile.w, h: this.profile.h
    });
    if (ack.status !== 'applied') throw new Error(ack.error || 'media_init_failed');
    if (this.recorder === rec) rec.start(this.chunkMs);
  };

  BcPublisher.prototype.restart = async function (gen) {
    this._stopRecorder();
    if (!this.stream) return;
    try { await this.start(gen); } catch (e) { this.onError(e.message); }
  };

  BcPublisher.prototype.applyProfile = async function (gen, profile) {
    this.profile = profile;
    var vt = this.stream && this.stream.getVideoTracks()[0];
    if (vt && this.source === 'camera') {
      try {
        await vt.applyConstraints({ width: { ideal: profile.w }, height: { ideal: profile.h }, frameRate: { ideal: profile.fps } });
      } catch (e) { /* camera pode nao suportar: segue com o que tiver */ }
    }
    await this.restart(gen);
  };

  BcPublisher.prototype.switchSource = async function (source) {
    if (source === this.source && this.stream) return;
    var prev = this.source;
    this.source = source;
    this._stopRecorder();
    var old = this.stream;
    try {
      this.stream = await this._capture();
    } catch (e) {
      this.source = prev;
      this.stream = old;
      this.onError('nao foi possivel capturar: ' + e.message);
      if (old) this.restart((this.gen + 1) & 0xFF);
      return;
    }
    if (old) old.getTracks().forEach(function (t) { t.stop(); });
    await this.restart((this.gen + 1) & 0xFF);
  };

  BcPublisher.prototype.setMuted = function (m) {
    this.muted = !!m;
    if (this.stream) this.stream.getAudioTracks().forEach(function (t) { t.enabled = !m; });
  };

  BcPublisher.prototype.stop = function () {
    this._stopRecorder();
    this._stopTracks();
    this.onPreview(null);
  };

  BcPublisher.prototype.active = function () {
    return !!this.recorder;
  };

  /* ------------------------------------------------------------ ouvinte */

  function normalizeMime(m) {
    var codecs = /codecs=([^;]+)/i.exec(m || '');
    var base = (m || 'video/webm').split(';')[0].trim();
    if (!codecs) return base + '; codecs="vp8,opus"';
    return base + '; codecs="' + codecs[1].replace(/"/g, '').trim() + '"';
  }

  function BcPlayer(video, opts) {
    this.video = video;
    this.onState = (opts && opts.onState) || function () {};
    this.ms = null;
    this.sb = null;
    this.queue = [];
    this.gen = -1;
    this.mime = '';
    this.bytes = 0;
    this.drops = 0;
    this.lastTs = 0;
    var self = this;
    this._timer = setInterval(function () { self._chase(); }, 1000);
  }

  BcPlayer.supported = function () {
    var MS = global.ManagedMediaSource || global.MediaSource;
    return !!(MS && MS.isTypeSupported && MS.isTypeSupported('video/webm; codecs="vp8,opus"'));
  };

  BcPlayer.prototype.reset = function (gen, mime) {
    var MS = global.ManagedMediaSource || global.MediaSource;
    var self = this;
    this.stop();
    if (!MS) { this.onState('unsupported'); return; }
    this.gen = gen & 0xFF;
    this.mime = normalizeMime(mime);
    if (MS.isTypeSupported && !MS.isTypeSupported(this.mime)) {
      this.onState('unsupported');
      return;
    }
    this.ms = new MS();
    if (global.ManagedMediaSource && MS === global.ManagedMediaSource) this.video.disableRemotePlayback = true;
    this.video.src = URL.createObjectURL(this.ms);
    this.ms.addEventListener('sourceopen', function () {
      try {
        self.sb = self.ms.addSourceBuffer(self.mime);
      } catch (e) {
        self.onState('unsupported');
        return;
      }
      self.sb.mode = 'segments';
      self.sb.addEventListener('updateend', function () { self._pump(); });
      self._pump();
    });
    this.onState('loading');
  };

  BcPlayer.prototype.push = function (ab) {
    if (ab.byteLength < 16) return;
    var v = new DataView(ab);
    if (v.getUint8(0) !== MAGIC) return;
    if (v.getUint8(3) !== this.gen) { this.drops++; return; }
    this.lastTs = v.getUint32(8) * 4294967296 + v.getUint32(12);
    this.bytes += ab.byteLength;
    this.queue.push(new Uint8Array(ab, 16));
    this._pump();
  };

  BcPlayer.prototype._pump = function () {
    var sb = this.sb;
    if (!sb || sb.updating || !this.queue.length) return;
    var chunk = this.queue.shift();
    try {
      sb.appendBuffer(chunk);
    } catch (e) {
      if (e.name === 'QuotaExceededError') {
        this.queue.unshift(chunk);
        var t = this.video.currentTime;
        try { sb.remove(0, Math.max(0, t - 5)); } catch (e2) { /* ignore */ }
      } else {
        this.onState('error');
      }
    }
  };

  BcPlayer.prototype._chase = function () {
    var vid = this.video, b = vid.buffered;
    if (!this.sb || !b || !b.length) return;
    var start = b.start(0), end = b.end(b.length - 1);
    if (vid.currentTime < start || end - vid.currentTime > 3) {
      vid.currentTime = Math.max(start, end - 0.5);
    }
    if (vid.paused) {
      var p = vid.play();
      if (p && p.catch) p.catch(function () { /* autoplay bloqueado: botao de som */ });
    }
    if (!this.sb.updating && vid.currentTime - start > 30) {
      try { this.sb.remove(start, vid.currentTime - 10); } catch (e) { /* ignore */ }
    }
    this.onState('playing');
  };

  BcPlayer.prototype.latencyMs = function () {
    return this.lastTs ? Date.now() - this.lastTs : 0;
  };

  BcPlayer.prototype.stop = function () {
    this.queue = [];
    this.sb = null;
    if (this.ms) {
      try { if (this.ms.readyState === 'open') this.ms.endOfStream(); } catch (e) { /* ignore */ }
    }
    this.ms = null;
    if (this.video.src) {
      URL.revokeObjectURL(this.video.src);
      this.video.removeAttribute('src');
      this.video.load();
    }
    this.gen = -1;
  };

  global.BcPublisher = BcPublisher;
  global.BcPlayer = BcPlayer;
})(window);
