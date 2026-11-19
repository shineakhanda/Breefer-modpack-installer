import React from 'react';
import {
    Alert, Badge, Button, Card, Check, ConfirmButton, Empty, Field, Input, Loading, Modal, Page, Pager, Select,
    api, formatNumber, getServerId, timeAgo, toastErr, toastOk, useDebounced,
} from './breefer-ui.js';

const { useState, useEffect } = React;

interface Pack {
    provider: string;
    id: string;
    title: string;
    description: string;
    icon: string | null;
    author: string | null;
    downloads: number;
    updated: string | null;
    loaders: string[];
    game_versions: string[];
    url: string | null;
}
interface PackVersion {
    id: string;
    name: string;
    type: string;
    game_versions: string[];
    loaders: string[];
    date: string | null;
    java: number | null;
    memory?: number | null;
}
interface Status {
    providers: string[];
    installing: boolean;
    server_status: string | null;
    history: Array<{ id: number; status: string; meta: any; created_at: string; updated_at: string }>;
}

const base = () => `/api/client/extensions/breefer-modpack-installer/servers/${getServerId()}`;
const LABEL: Record<string, string> = { modrinth: 'Modrinth', curseforge: 'CurseForge', ftb: 'Feed The Beast' };

export default function ModpackPage() {
    const [status, setStatus] = useState<Status | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [provider, setProvider] = useState('modrinth');
    const [query, setQuery] = useState('');
    const [loader, setLoader] = useState('');
    const [version, setVersion] = useState('');
    const [sort, setSort] = useState('relevance');
    const [page, setPage] = useState(1);
    const debounced = useDebounced(query, 450);
    const debouncedVersion = useDebounced(version, 600);
    const [results, setResults] = useState<{ items: Pack[]; pages: number } | null>(null);
    const [loading, setLoading] = useState(false);

    const [pack, setPack] = useState<Pack | null>(null);
    const [versions, setVersions] = useState<PackVersion[] | null>(null);
    const [choice, setChoice] = useState<PackVersion | null>(null);
    const [wipe, setWipe] = useState(false);
    const [eula, setEula] = useState(true);
    const [starting, setStarting] = useState(false);

    const loadStatus = () =>
        api<Status>('GET', base() + '/status')
            .then((s) => {
                setStatus(s);
                if (s.providers.length && !s.providers.includes(provider)) setProvider(s.providers[0]);
                return s;
            })
            .catch((e) => {
                setError(e.message);
                return null;
            });

    useEffect(() => {
        loadStatus();
    }, []);

    // Poll while an installation is running.
    useEffect(() => {
        if (!status?.installing) return;
        const t = setInterval(() => {
            loadStatus().then((s) => {
                if (s && !s.installing) toastOk('Modpack installation finished. You can start the server now.');
            });
        }, 8000);
        return () => clearInterval(t);
    }, [status?.installing]);

    useEffect(() => setPage(1), [debounced, provider, loader, debouncedVersion, sort]);

    useEffect(() => {
        if (!status || !status.providers.includes(provider)) return;
        let alive = true;
        setLoading(true);
        const qs = new URLSearchParams({ provider, query: debounced, sort, page: String(page) });
        if (loader) qs.set('loader', loader);
        if (debouncedVersion) qs.set('version', debouncedVersion);
        api('GET', `${base()}/search?${qs}`)
            .then((d: any) => alive && setResults(d))
            .catch((e) => alive && toastErr(e.message))
            .finally(() => alive && setLoading(false));
        return () => {
            alive = false;
        };
    }, [status?.providers.join(','), provider, debounced, loader, debouncedVersion, sort, page]);

    const open = (p: Pack) => {
        setPack(p);
        setVersions(null);
        setChoice(null);
        api('GET', `${base()}/packs/${p.provider}/${encodeURIComponent(p.id)}/versions`)
            .then((d: any) => {
                setVersions(d.versions);
                setChoice(d.versions[0] ?? null);
            })
            .catch((e) => {
                toastErr(e.message);
                setVersions([]);
            });
    };

    const install = async () => {
        if (!pack || !choice) return;
        setStarting(true);
        try {
            await api('POST', base() + '/install', {
                provider: pack.provider,
                pack_id: pack.id,
                version_id: choice.id,
                delete_files: wipe,
                accept_eula: eula,
                title: pack.title,
                version_name: choice.name,
            });
            toastOk('Installation started — watch the console for progress.');
            setPack(null);
            await loadStatus();
        } catch (e: any) {
            toastErr(e.message);
        } finally {
            setStarting(false);
        }
    };

    const restore = async () => {
        try {
            await api('POST', base() + '/restore');
            toastOk('Server restored to its original egg.');
            await loadStatus();
        } catch (e: any) {
            toastErr(e.message);
        }
    };

    const running = status?.history.find((h) => h.status === 'running');

    return (
        <Page title='Modpacks' subtitle='Install complete modpacks with the correct loader, mods and configs.' letter='P'>
            {error ? <Alert kind='err'>{error}</Alert> : null}
            {!status && !error ? <Loading /> : null}
            {status ? (
                <>
                    {status.installing ? (
                        <Alert kind='warn'>
                            <div className='brf-row brf-between'>
                                <span>
                                    Installing <b>{running?.meta?.title ?? 'a modpack'}</b> {running?.meta?.version ? `(${running.meta.version})` : ''} — started{' '}
                                    {timeAgo(running?.created_at)}. Open the console to follow progress; the server is restored automatically when it finishes.
                                </span>
                                <ConfirmButton
                                    size='sm'
                                    variant='sec'
                                    title='Restore the server now?'
                                    message='Only do this if the installation is stuck. The server is switched back to its original egg immediately.'
                                    confirmText='Restore'
                                    onConfirm={restore}
                                >
                                    Restore
                                </ConfirmButton>
                            </div>
                        </Alert>
                    ) : null}
                    {status.providers.length === 0 ? (
                        <Empty title='No providers enabled' text='Ask your administrator to enable a modpack provider.' />
                    ) : (
                        <>
                            <Card style={{ marginBottom: 14 }}>
                                <div className='brf-row'>
                                    <Field label='Source' style={{ minWidth: 150 }}>
                                        <Select value={provider} onValue={setProvider} options={status.providers.map((p) => ({ value: p, label: LABEL[p] ?? p }))} />
                                    </Field>
                                    <Field label='Loader' style={{ minWidth: 130 }}>
                                        <Select
                                            value={loader}
                                            onValue={setLoader}
                                            options={[{ value: '', label: 'Any' }, 'fabric', 'forge', 'neoforge', 'quilt']}
                                        />
                                    </Field>
                                    <Field label='Minecraft version' style={{ minWidth: 130 }}>
                                        <Input value={version} onValue={setVersion} placeholder='e.g. 1.20.1' />
                                    </Field>
                                    {provider !== 'ftb' ? (
                                        <Field label='Sort' style={{ minWidth: 140 }}>
                                            <Select
                                                value={sort}
                                                onValue={setSort}
                                                options={[
                                                    { value: 'relevance', label: 'Relevance' },
                                                    { value: 'downloads', label: 'Downloads' },
                                                    { value: 'updated', label: 'Recently updated' },
                                                    { value: 'newest', label: 'Newest' },
                                                ]}
                                            />
                                        </Field>
                                    ) : null}
                                </div>
                                <Input style={{ marginTop: 12 }} placeholder='Search modpacks…' value={query} onValue={setQuery} />
                            </Card>

                            {!results ? (
                                <Loading />
                            ) : results.items.length === 0 ? (
                                <Empty title='No modpacks found' text='Try a different search or filter.' />
                            ) : (
                                <>
                                    <div className='brf-grid' style={{ ['--brf-min' as any]: '300px', opacity: loading ? 0.6 : 1 }}>
                                        {results.items.map((p) => (
                                            <div key={p.provider + p.id} className='brf-item brf-click' style={{ alignItems: 'flex-start' }} onClick={() => open(p)}>
                                                {p.icon ? <img className='brf-thumb' src={p.icon} alt='' loading='lazy' style={{ width: 56, height: 56 }} /> : <div className='brf-thumb' />}
                                                <div className='brf-grow'>
                                                    <p className='brf-title'>{p.title}</p>
                                                    <div className='brf-muted brf-clamp'>{p.description}</div>
                                                    <div className='brf-row' style={{ gap: 6, marginTop: 6 }}>
                                                        {p.loaders.slice(0, 2).map((l) => (
                                                            <Badge key={l} kind='pri'>
                                                                {l}
                                                            </Badge>
                                                        ))}
                                                        {p.game_versions.slice(0, 2).map((v) => (
                                                            <Badge key={v}>{v}</Badge>
                                                        ))}
                                                        <span className='brf-muted'>⬇ {formatNumber(p.downloads)}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                    <Pager page={page} pages={results.pages} onPage={setPage} />
                                </>
                            )}
                        </>
                    )}
                </>
            ) : null}

            <Modal open={!!pack} onClose={() => !starting && setPack(null)} title={pack?.title} width={640}>
                {pack ? (
                    <div className='brf-col'>
                        <div className='brf-row' style={{ gap: 12 }}>
                            {pack.icon ? <img className='brf-thumb' src={pack.icon} alt='' style={{ width: 64, height: 64 }} /> : null}
                            <div className='brf-grow brf-muted'>
                                {pack.description}
                                {pack.author ? <div>by {pack.author}</div> : null}
                            </div>
                        </div>
                        {pack.url ? (
                            <a href={pack.url} target='_blank' rel='noopener noreferrer'>
                                View on {LABEL[pack.provider]} ↗
                            </a>
                        ) : null}
                        {!versions ? (
                            <Loading />
                        ) : versions.length === 0 ? (
                            <Empty title='No installable versions' />
                        ) : (
                            <>
                                <Field label='Version'>
                                    <Select
                                        value={choice?.id ?? ''}
                                        onValue={(v) => setChoice(versions.find((x) => x.id === v) ?? null)}
                                        options={versions.map((v) => ({
                                            value: v.id,
                                            label: `${v.name}${v.game_versions[0] ? ' — MC ' + v.game_versions[0] : ''}${v.loaders[0] ? ' · ' + v.loaders[0] : ''}${v.type !== 'release' ? ' (' + v.type + ')' : ''}`,
                                        }))}
                                    />
                                </Field>
                                {choice?.java || choice?.memory ? (
                                    <div className='brf-row'>
                                        {choice.java ? <Badge kind='warn'>Needs Java {choice.java}</Badge> : null}
                                        {choice.memory ? <Badge>Recommended RAM {Math.round(choice.memory / 1024)} GB</Badge> : null}
                                    </div>
                                ) : null}
                                <Alert kind='info' style={{ marginBottom: 0 }}>
                                    The server is stopped and reinstalled with the modpack. Existing <code>mods/</code> and loader libraries are replaced; your worlds are kept unless you choose to delete all files.
                                    Make sure the server's Java image matches the pack.
                                </Alert>
                                <Check checked={wipe} onValue={setWipe} label='Delete ALL server files first (fresh install, worlds included)' />
                                <Check checked={eula} onValue={setEula} label='I accept the Minecraft EULA' />
                                <div className='brf-row brf-end'>
                                    <Button variant='sec' disabled={starting} onClick={() => setPack(null)}>
                                        Cancel
                                    </Button>
                                    <Button variant={wipe ? 'danger' : 'primary'} loading={starting} disabled={!choice || status?.installing} onClick={install}>
                                        Install modpack
                                    </Button>
                                </div>
                            </>
                        )}
                    </div>
                ) : null}
            </Modal>
        </Page>
    );
}
