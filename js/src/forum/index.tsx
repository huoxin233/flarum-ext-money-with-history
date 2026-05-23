import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import UserCard from 'flarum/forum/components/UserCard';
import UserControls from 'flarum/forum/utils/UserControls';
import UserPage from 'flarum/forum/components/UserPage';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import Model from 'flarum/common/Model';
import User from 'flarum/common/models/User';
import UserMoneyModal from './components/UserMoneyModal';
import MoneyHistoryPage from './components/MoneyHistoryPage';
import UserMoneyHistory from './models/UserMoneyHistory';

app.initializers.add('huoxin/money-with-history', () => {
  // Register models and attributes
  User.prototype.canEditMoney = Model.attribute<boolean>('canEditMoney');
  app.store.models.userMoneyHistory = UserMoneyHistory;

  // Register history page route
  app.routes.userMoneyHistory = {
    path: '/u/:username/money/history',
    component: MoneyHistoryPage,
  };

  // Show money on user card
  extend(UserCard.prototype, 'infoItems', function (this: UserCard, items) {
    const moneyName = app.forum.attribute<string>('huoxin-money-with-history.moneyname') || '[money]';
    const user = (this.attrs as { user: User }).user;
    const money = user.attribute<number>('money');

    if (app.forum.attribute('huoxin-money-with-history.noshowzero') == 1) {
      if (money !== 0) {
        items.add('money', <span>{moneyName.replace('[money]', String(money))}</span>);
      }
    } else {
      items.add('money', <span>{moneyName.replace('[money]', String(money))}</span>);
    }
  });

  // Add edit money button to user moderation controls
  extend(UserControls, 'moderationControls', (items, user) => {
    if (user.canEditMoney()) {
      items.add(
        'money',
        Button.component(
          {
            icon: 'fas fa-money-bill',
            onclick: () => app.modal.show(UserMoneyModal, { user }),
          },
          app.translator.trans('huoxin-money-with-history.forum.user_controls.money_button')
        )
      );
    }
  });

  // Add money history link to user profile nav
  extend(UserPage.prototype, 'navItems', function (items) {
    if (!app.session.user || app.session.user.id() !== this.user!.id()) {
      if (!this.user || !this.user.attribute('canQueryOthersMoneyHistory')) {
        return;
      }
    }

    items.add(
      'userMoneyHistory',
      LinkButton.component(
        {
          href: app.route('userMoneyHistory', {
            username: this.user!.slug(),
          }),
          icon: 'fas fa-money-bill',
        },
        app.translator.trans('huoxin-money-with-history.forum.nav')
      )
    );
  });
});
