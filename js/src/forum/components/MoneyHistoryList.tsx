import app from 'flarum/forum/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import type User from 'flarum/common/models/User';
import type UserMoneyHistory from '../models/UserMoneyHistory';

import MoneyHistoryListItem from './MoneyHistoryListItem';

interface MoneyHistoryListAttrs extends ComponentAttrs {
  params: {
    user: User;
  };
}

export default class MoneyHistoryList extends Component<MoneyHistoryListAttrs> {
  loading = true;
  moreResults = false;
  historyEntries: UserMoneyHistory[] = [];
  user!: User;

  oninit(vnode: Mithril.Vnode<MoneyHistoryListAttrs>) {
    super.oninit(vnode);
    this.user = this.attrs.params.user;
    this.loadResults();
  }

  view(): Mithril.Children {
    return (
      <div>
        <div style="padding-bottom:10px; font-size: 24px;font-weight: bold;">{app.translator.trans('huoxin-money-with-history.forum.title')}</div>
        <ul style="margin: 0;padding: 0;list-style-type: none;position: relative;">
          {this.historyEntries.map((historyEntry) => (
            <li style="padding-top:5px" key={historyEntry.id()} data-id={historyEntry.id()}>
              <MoneyHistoryListItem historyEntry={historyEntry} />
            </li>
          ))}
        </ul>

        {!this.loading && this.historyEntries.length === 0 && (
          <div>
            <div style="font-size:1.4em;color: var(--muted-more-color);text-align: center;height: 300px;line-height: 100px;">
              {app.translator.trans('huoxin-money-with-history.forum.list-empty')}
            </div>
          </div>
        )}

        {this.hasMoreResults() && (
          <div style="text-align:center;padding:20px">
            <Button className={'Button Button--primary'} disabled={this.loading} loading={this.loading} onclick={() => this.loadMore()}>
              {app.translator.trans('huoxin-money-with-history.forum.money-list-load-more')}
            </Button>
          </div>
        )}

        {this.loading && <LoadingIndicator display="block" />}
      </div>
    );
  }

  loadMore(): void {
    this.loading = true;
    this.loadResults(this.historyEntries.length);
  }

  parseResults(historyEntries: UserMoneyHistory[] & { payload?: { links?: { next?: string } } }): UserMoneyHistory[] {
    this.moreResults = !!historyEntries.payload?.links?.next;
    this.historyEntries.push(...historyEntries);
    this.loading = false;
    m.redraw();

    return historyEntries;
  }

  hasMoreResults(): boolean {
    return this.moreResults;
  }

  loadResults(offset = 0): Promise<UserMoneyHistory[]> {
    this.loading = true;
    const historyUrl = '/users/' + this.user.id() + '/money/history';
    return app.store
      .find<UserMoneyHistory[]>(historyUrl, {
        page: { offset },
      })
      .then(this.parseResults.bind(this))
      .catch((err) => {
        this.loading = false;
        m.redraw();
        throw err;
      });
  }
}
