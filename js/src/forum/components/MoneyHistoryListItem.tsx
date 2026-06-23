import app from 'flarum/forum/app';
import Component, { ComponentAttrs } from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import avatar from 'flarum/common/helpers/avatar';
import username from 'flarum/common/helpers/username';
import type Mithril from 'mithril';
import dayjs from 'dayjs';
import type UserMoneyHistory from '../models/UserMoneyHistory';

interface MoneyHistoryListItemAttrs extends ComponentAttrs {
  historyEntry: UserMoneyHistory;
}

function buildSourceDescription(historyEntry: UserMoneyHistory): string | Mithril.Children {
  const sourceKey = historyEntry.sourceKey();
  const sourceParams = (historyEntry.sourceParams() || {}) as Record<string, unknown>;

  if (!sourceKey) {
    return historyEntry.source() || '';
  }

  const translationParams: Record<string, unknown> = {};

  Object.entries(sourceParams).forEach(([key, value]) => {
    if (key.endsWith('LinkHref') && typeof value === 'string' && value !== '') {
      translationParams[key.slice(0, -4)] = Link.component({
        href: value,
      });
      return;
    }

    if (value !== null && typeof value === 'object') {
      return;
    }

    if (key.endsWith('Key') && typeof value === 'string') {
      translationParams[key.slice(0, -3)] = app.translator.trans(value);
      return;
    }

    translationParams[key] = value;
  });

  return app.translator.trans(sourceKey, translationParams);
}

export default class MoneyHistoryListItem extends Component<MoneyHistoryListItemAttrs> {
  view(): Mithril.Children {
    const { historyEntry } = this.attrs;
    const createdAt = historyEntry.createdAt();
    const balanceDelta = historyEntry.balanceDelta();
    const sourceDescription = buildSourceDescription(historyEntry);
    const historyId = historyEntry.id();
    const actor = historyEntry.actor() || null;
    const balanceBefore = historyEntry.balanceBefore();
    const balanceAfter = historyEntry.balanceAfter();
    const isDebit = balanceDelta < 0;
    const changeAmount = Math.abs(balanceDelta);
    const moneyType = app.translator.trans(
      isDebit ? 'huoxin-money-with-history.forum.record.money-out' : 'huoxin-money-with-history.forum.record.money-in'
    );
    const moneyTypeStyle = isDebit ? 'color:red' : 'color:green';

    return (
      <div className="MoneyHistoryCard">
        <div className="MoneyHistoryCard-header">
          <div className="MoneyHistoryCard-stat">
            <span className="MoneyHistoryCard-stat-label">{app.translator.trans('huoxin-money-with-history.forum.record.money-list-type')}</span>
            <span className="MoneyHistoryCard-stat-value" style={moneyTypeStyle}>
              {moneyType}
            </span>
          </div>
          <div className="MoneyHistoryCard-stat time">
            <span className="MoneyHistoryCard-stat-label">{app.translator.trans('huoxin-money-with-history.forum.record.money-list-assign-at')}</span>
            <span className="MoneyHistoryCard-stat-value">{dayjs(createdAt).format('YYYY-MM-DD HH:mm:ss')}</span>
          </div>
        </div>

        <div className="MoneyHistoryCard-body">
          <div className="MoneyHistoryCard-stat">
            <span className="MoneyHistoryCard-stat-label">{app.translator.trans('huoxin-money-with-history.forum.record.money-list-id')}</span>
            <span className="MoneyHistoryCard-stat-value">{historyId}</span>
          </div>
          <div className="MoneyHistoryCard-stat">
            <span className="MoneyHistoryCard-stat-label">{app.translator.trans('huoxin-money-with-history.forum.record.money-list-from-user')}</span>
            <span className="MoneyHistoryCard-stat-value">
              <Link href={app.route('user', { username: actor?.slug() })} className="MoneyHistoryCard-user">
                {avatar(actor)} {username(actor)}
              </Link>
            </span>
          </div>
          <div className="MoneyHistoryCard-stat">
            <span className="MoneyHistoryCard-stat-label">{app.translator.trans('huoxin-money-with-history.forum.record.money-list-amount')}</span>
            <span className="MoneyHistoryCard-stat-value" style={moneyTypeStyle}>
              {isDebit ? '-' : '+'}
              {changeAmount}
            </span>
          </div>
          <div className="MoneyHistoryCard-stat">
            <span className="MoneyHistoryCard-stat-label">{app.translator.trans('huoxin-money-with-history.forum.record.money-list-balance')}</span>
            <span className="MoneyHistoryCard-stat-value">
              {balanceBefore} &rarr; {balanceAfter}
            </span>
          </div>
          <div className="MoneyHistoryCard-stat">
            <span className="MoneyHistoryCard-stat-label">
              {app.translator.trans('huoxin-money-with-history.forum.record.money-list-transfer-notes')}
            </span>
            <span className="MoneyHistoryCard-stat-value notes">{sourceDescription}</span>
          </div>
        </div>
      </div>
    );
  }
}
